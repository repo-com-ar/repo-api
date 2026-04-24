<?php
/**
 * Push Service — API de alto nivel para las 3 apps.
 *
 * Uso:
 *   require_once __DIR__ . '/../../repo-api/lib/pushservice.php';
 *   push_enviar_a('repartidor', 5, 'Nuevo pedido', 'Tenés un pedido para retirar', [
 *       'url' => 'https://delivery.repo.com.ar/',
 *       'tag' => 'pedido-1234',
 *   ]);
 *
 * Funciones de suscripción (usadas por los endpoints api/push.php de cada app):
 *   push_upsert(actor_type, actor_id, endpoint, p256dh, auth, origin, user_agent)
 *   push_delete(actor_type, actor_id, endpoint)
 *   push_list(actor_type = null, actor_id = null)
 */

require_once __DIR__ . '/webpush.php';
require_once __DIR__ . '/../config/db.php';

// ─── Migración perezosa ────────────────────────────────────────────

/**
 * Se ejecuta automáticamente la primera vez que cualquier función de push
 * toca la DB en este request. Crea la tabla y genera las claves VAPID
 * si todavía no existen. Idempotente y cacheada por request.
 */
function push_ensure_schema(): void {
    static $ensured = false;
    if ($ensured) { return; }
    $pdo = getDB();

    // 1. Tabla push_subscriptions
    try {
        $pdo->query("SELECT id FROM push_subscriptions LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS push_subscriptions (
                id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                actor_type    ENUM('repartidor','cliente','usuario') NOT NULL,
                actor_id      INT UNSIGNED NOT NULL,
                origin        VARCHAR(120) NOT NULL DEFAULT '',
                endpoint      VARCHAR(500) NOT NULL,
                endpoint_hash CHAR(64)     NOT NULL,
                p256dh        VARCHAR(255) NOT NULL,
                auth_key      VARCHAR(255) NOT NULL,
                user_agent    VARCHAR(255) NOT NULL DEFAULT '',
                created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                last_used_at  TIMESTAMP    NULL DEFAULT NULL,
                last_error    VARCHAR(255) NOT NULL DEFAULT '',
                UNIQUE KEY uk_endpoint_hash (endpoint_hash),
                INDEX idx_actor (actor_type, actor_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    // 2. Claves VAPID en la tabla configuracion
    try {
        $have = (int)$pdo->query(
            "SELECT COUNT(*) FROM configuracion
             WHERE clave IN ('vapid_public_key','vapid_private_key')
               AND valor <> ''"
        )->fetchColumn();
    } catch (Exception $e) {
        $have = 0; // si no existe la tabla configuracion, no podemos generar — dejar para install.php
    }

    if ($have < 2) {
        $keyRes = @openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        if ($keyRes !== false) {
            $details    = openssl_pkey_get_details($keyRes);
            $x          = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
            $y          = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
            $d          = str_pad($details['ec']['d'], 32, "\x00", STR_PAD_LEFT);
            $publicRaw  = "\x04" . $x . $y;
            $privateRaw = $d;
            $b64u = function ($bin) { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); };

            $pdo->prepare("INSERT INTO configuracion (clave, valor) VALUES (?, ?)
                           ON DUPLICATE KEY UPDATE valor = VALUES(valor)")
                ->execute(['vapid_public_key',  $b64u($publicRaw)]);
            $pdo->prepare("INSERT INTO configuracion (clave, valor) VALUES (?, ?)
                           ON DUPLICATE KEY UPDATE valor = VALUES(valor)")
                ->execute(['vapid_private_key', $b64u($privateRaw)]);
            $pdo->prepare("INSERT IGNORE INTO configuracion (clave, valor) VALUES (?, ?)")
                ->execute(['vapid_subject', 'mailto:admin@repo.com.ar']);
        }
    }

    $ensured = true;
}

// ─── Gestión de suscripciones ──────────────────────────────────────

/** Inserta o actualiza una suscripción. Un endpoint = una sola fila. */
function push_upsert(string $actor_type, int $actor_id, string $endpoint, string $p256dh, string $auth, string $origin = '', string $user_agent = ''): int {
    push_ensure_schema();
    $pdo  = getDB();
    $hash = hash('sha256', $endpoint);
    $stmt = $pdo->prepare("
        INSERT INTO push_subscriptions
            (actor_type, actor_id, origin, endpoint, endpoint_hash, p256dh, auth_key, user_agent, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            actor_type = VALUES(actor_type),
            actor_id   = VALUES(actor_id),
            origin     = VALUES(origin),
            p256dh     = VALUES(p256dh),
            auth_key   = VALUES(auth_key),
            user_agent = VALUES(user_agent),
            last_error = ''
    ");
    $stmt->execute([$actor_type, $actor_id, $origin, $endpoint, $hash, $p256dh, $auth, $user_agent]);
    return (int)($pdo->lastInsertId() ?: 0);
}

function push_delete_by_endpoint(string $endpoint): bool {
    push_ensure_schema();
    $pdo  = getDB();
    $hash = hash('sha256', $endpoint);
    $stmt = $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint_hash = ?");
    $stmt->execute([$hash]);
    return $stmt->rowCount() > 0;
}

function push_list(?string $actor_type = null, ?int $actor_id = null): array {
    push_ensure_schema();
    $pdo = getDB();
    $where = [];
    $params = [];
    if ($actor_type !== null) {
        $where[] = 'actor_type = ?';
        $params[] = $actor_type;
    }
    if ($actor_id !== null) {
        $where[] = 'actor_id = ?';
        $params[] = $actor_id;
    }
    $sql = "SELECT id, actor_type, actor_id, origin, endpoint, p256dh, auth_key,
                   user_agent, created_at, last_used_at, last_error
            FROM push_subscriptions"
         . (count($where) ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// ─── Envío ──────────────────────────────────────────────────────────

/** Lee VAPID keys desde configuracion. Cacheado por request. */
function push_vapid_config(): array {
    static $cache = null;
    if ($cache !== null) { return $cache; }
    push_ensure_schema();
    $pdo = getDB();
    $rows = $pdo->query("
        SELECT clave, valor FROM configuracion
        WHERE clave IN ('vapid_public_key','vapid_private_key','vapid_subject')
    ")->fetchAll();
    $m = [];
    foreach ($rows as $r) { $m[$r['clave']] = $r['valor']; }
    $cache = [
        'public'  => $m['vapid_public_key']  ?? '',
        'private' => $m['vapid_private_key'] ?? '',
        'subject' => $m['vapid_subject']     ?? 'mailto:admin@repo.com.ar',
    ];
    return $cache;
}

/**
 * Envía una notificación push al actor dado.
 *
 * @param string $actor_type 'repartidor' | 'cliente' | 'usuario'
 * @param int|null $actor_id id del actor. null = broadcast a todos los actor_type.
 * @param string $titulo
 * @param string $body
 * @param array $data payload extra que recibe el SW (url, tag, icon, pedido_id, etc.)
 * @return array estadísticas: ['enviados' => N, 'fallidos' => N, 'muertos' => N]
 */
function push_enviar_a(string $actor_type, ?int $actor_id, string $titulo, string $body, array $data = []): array {
    $vapid = push_vapid_config();
    $stats = ['enviados' => 0, 'fallidos' => 0, 'muertos' => 0];

    if (empty($vapid['public']) || empty($vapid['private'])) {
        return $stats + ['error' => 'VAPID keys no configuradas — correr install.php'];
    }

    $subs = push_list($actor_type, $actor_id);
    if (!$subs) { return $stats; }

    $payload = json_encode([
        'title' => $titulo,
        'body'  => $body,
        'data'  => $data,
    ], JSON_UNESCAPED_UNICODE);

    $pdo = getDB();
    foreach ($subs as $s) {
        $res = webpush_send([
            'endpoint' => $s['endpoint'],
            'p256dh'   => $s['p256dh'],
            'auth'     => $s['auth_key'],
        ], $payload, $vapid);

        $st = $res['status'];
        if ($st >= 200 && $st < 300) {
            $pdo->prepare("UPDATE push_subscriptions SET last_used_at = NOW(), last_error = '' WHERE id = ?")
                ->execute([$s['id']]);
            $stats['enviados']++;
        } elseif ($st === 404 || $st === 410) {
            // Suscripción expirada o cancelada por el usuario → limpiar
            $pdo->prepare("DELETE FROM push_subscriptions WHERE id = ?")->execute([$s['id']]);
            $stats['muertos']++;
        } else {
            $err = substr(($res['error'] ?: 'HTTP ' . $st . ' ' . $res['body']), 0, 250);
            $pdo->prepare("UPDATE push_subscriptions SET last_error = ? WHERE id = ?")
                ->execute([$err, $s['id']]);
            $stats['fallidos']++;
        }
    }
    return $stats;
}
