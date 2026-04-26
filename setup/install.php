<?php
/**
 * Script de instalación: crea tablas e inserta datos de ejemplo.
 * Acceder desde el navegador: https://tu-servidor/setup/install.php
 * Se ejecuta UNA sola vez.
 */

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/../config/db.php';

$log = [];
$ok  = true;

function msg($texto, $tipo = 'info') {
    global $log;
    $colores = ['ok' => '#22c55e', 'error' => '#ef4444', 'info' => '#3b82f6', 'warn' => '#f59e0b'];
    $color = $colores[$tipo] ?? $colores['info'];
    $log[] = "<div style='padding:6px 0;color:{$color}'>● {$texto}</div>";
}

// ── Conexión ──
try {
    $pdo = getDB();
    msg("Conectado a la base de datos '<b>" . DB_NAME . "</b>' en <b>" . DB_HOST . "</b>", 'ok');
} catch (Exception $e) {
    msg("Error de conexión: " . htmlspecialchars($e->getMessage()), 'error');
    $ok = false;
}

if ($ok):

// ── 1. Crear tablas ──────────────────────────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS categorias (
            id          VARCHAR(50)  PRIMARY KEY,
            label       VARCHAR(80)  NOT NULL,
            emoji       VARCHAR(10)  NOT NULL DEFAULT '📦',
            imagen      VARCHAR(500) DEFAULT '',
            orden       INT UNSIGNED NOT NULL DEFAULT 0,
            activa      TINYINT(1)   NOT NULL DEFAULT 1,
            productos   INT UNSIGNED NOT NULL DEFAULT 0,
            parent_id   VARCHAR(50)  NULL DEFAULT NULL,
            created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_parent_id (parent_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    msg("Tabla <b>categorias</b> creada/verificada", 'ok');
} catch (Exception $e) {
    msg("Error creando tabla categorias: " . htmlspecialchars($e->getMessage()), 'error');
    $ok = false;
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS productos (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sku         INT UNSIGNED NOT NULL DEFAULT 0,
            ean         VARCHAR(20)  DEFAULT '',
            nombre      VARCHAR(120) NOT NULL,
            precio_compra DECIMAL(10,2) NOT NULL DEFAULT 0,
            margen        DECIMAL(5,2)  NOT NULL DEFAULT 0,
            precio_venta  DECIMAL(10,2) NOT NULL DEFAULT 0,
            categoria   VARCHAR(50)  NOT NULL,
            imagen      VARCHAR(500) DEFAULT '',
            contenido   VARCHAR(50)  DEFAULT NULL,
            unidad      VARCHAR(10)  DEFAULT 'u',
            stock_actual      INT NOT NULL DEFAULT 1,
            stock_comprometido INT NOT NULL DEFAULT 0,
            stock_minimo      INT NOT NULL DEFAULT 0,
            stock_recomendado INT NOT NULL DEFAULT 3,
            created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            updated_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_sku (sku)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    msg("Tabla <b>productos</b> creada/verificada", 'ok');
} catch (Exception $e) {
    msg("Error creando tabla productos: " . htmlspecialchars($e->getMessage()), 'error');
    $ok = false;
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS clientes (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            nombre      VARCHAR(120) NOT NULL,
            correo      VARCHAR(150) DEFAULT NULL,
            celular     VARCHAR(40)  DEFAULT '',
            direccion   VARCHAR(255) DEFAULT '',
            contrasena  VARCHAR(100) NOT NULL DEFAULT '',
            clave       VARCHAR(100) NOT NULL DEFAULT '',
            lat         DECIMAL(10,7) DEFAULT NULL,
            lng         DECIMAL(10,7) DEFAULT NULL,
            created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            updated_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    msg("Tabla <b>clientes</b> creada/verificada", 'ok');
} catch (Exception $e) {
    msg("Error creando tabla clientes: " . htmlspecialchars($e->getMessage()), 'error');
    $ok = false;
}

// Migración: eliminar columna emoji de productos
try {
    $hasEmoji = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos' AND COLUMN_NAME = 'emoji'")->fetchColumn();
    if ($hasEmoji) {
        $pdo->exec("ALTER TABLE productos DROP COLUMN emoji");
        msg("Migración <b>productos</b>: columna emoji eliminada", 'ok');
    }
} catch (Exception $e) {
    msg("Error migrando productos (emoji): " . htmlspecialchars($e->getMessage()), 'error');
}

// Migración: renombrar campo codigo a sku en productos
try {
    $hasCodigo = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos' AND COLUMN_NAME = 'codigo'")->fetchColumn();
    if ($hasCodigo) {
        $pdo->exec("ALTER TABLE productos CHANGE COLUMN codigo sku INT UNSIGNED NOT NULL DEFAULT 0");
        try { $pdo->exec("ALTER TABLE productos DROP INDEX uk_codigo"); } catch (Exception $e) { /* puede no existir */ }
        try { $pdo->exec("ALTER TABLE productos ADD UNIQUE KEY uk_sku (sku)"); } catch (Exception $e) { /* ya existe */ }
        msg("Migración <b>productos</b>: campo codigo renombrado a sku", 'ok');
    }
} catch (Exception $e) {
    msg("Error migrando productos (codigo→sku): " . htmlspecialchars($e->getMessage()), 'error');
}

// Eliminar trigger obsoleto si aún existe
try {
    $triggerExiste = $pdo->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TRIGGERS
         WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = 'tr_productos_sku'"
    )->fetchColumn();
    if ($triggerExiste) {
        $pdo->exec("DROP TRIGGER tr_productos_sku");
        msg("Trigger <b>tr_productos_sku</b> eliminado (SKU ahora se genera en PHP)", 'ok');
    } else {
        msg("Trigger <b>tr_productos_sku</b> no existe, nada que eliminar", 'info');
    }
} catch (Exception $e) {
    msg("Aviso al eliminar trigger tr_productos_sku: " . htmlspecialchars($e->getMessage()), 'warn');
}

// Migración: telefono → celular + reordenar correo en clientes
try {
    $hasTelefono = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clientes' AND COLUMN_NAME = 'telefono'")->fetchColumn();
    if ($hasTelefono) {
        $pdo->exec("ALTER TABLE clientes CHANGE telefono celular VARCHAR(40) DEFAULT '', MODIFY correo VARCHAR(150) DEFAULT NULL AFTER nombre");
        msg("Migración <b>clientes</b>: telefono → celular", 'ok');
    }
} catch (Exception $e) {
    msg("Error migrando clientes: " . htmlspecialchars($e->getMessage()), 'error');
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pedidos (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            numero      VARCHAR(20)  NOT NULL UNIQUE,
            cliente_id  INT UNSIGNED DEFAULT NULL,
            cliente     VARCHAR(120) NOT NULL,
            correo      VARCHAR(120) DEFAULT NULL,
            celular     VARCHAR(40)  DEFAULT '',
            direccion   VARCHAR(255) DEFAULT '',
            notas       TEXT,
            subtotal    DECIMAL(12,2) NOT NULL DEFAULT 0,
            envio       DECIMAL(12,2) NOT NULL DEFAULT 0,
            total       DECIMAL(12,2) NOT NULL DEFAULT 0,
            deposito_id INT UNSIGNED DEFAULT NULL,
            estado      VARCHAR(30)  NOT NULL DEFAULT 'recibido',
            retiro_lat  DECIMAL(10,7) DEFAULT NULL,
            retiro_lng  DECIMAL(10,7) DEFAULT NULL,
            entrega_lat DECIMAL(10,7) DEFAULT NULL,
            entrega_lng DECIMAL(10,7) DEFAULT NULL,
            distancia_km DECIMAL(8,2) DEFAULT NULL,
            tiempo_min  INT UNSIGNED DEFAULT NULL,
            created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            updated_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    msg("Tabla <b>pedidos</b> creada/verificada", 'ok');
} catch (Exception $e) {
    msg("Error creando tabla pedidos: " . htmlspecialchars($e->getMessage()), 'error');
    $ok = false;
}

// Migración: telefono → celular + agregar correo en pedidos
try {
    $hasTelefono = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND COLUMN_NAME = 'telefono'")->fetchColumn();
    if ($hasTelefono) {
        $pdo->exec("ALTER TABLE pedidos CHANGE telefono celular VARCHAR(40) DEFAULT ''");
        msg("Migración <b>pedidos</b>: telefono → celular", 'ok');
    }
    $hasCorreo = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND COLUMN_NAME = 'correo'")->fetchColumn();
    if (!$hasCorreo) {
        $pdo->exec("ALTER TABLE pedidos ADD COLUMN correo VARCHAR(120) DEFAULT NULL AFTER cliente, MODIFY celular VARCHAR(40) DEFAULT '' AFTER correo");
        msg("Migración <b>pedidos</b>: columna correo agregada", 'ok');
    }
} catch (Exception $e) {
    msg("Error migrando pedidos: " . htmlspecialchars($e->getMessage()), 'error');
}

// Migración: agregar columnas de horario de entrega en pedidos
try {
    $hasEntregaFecha = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND COLUMN_NAME = 'entrega_fecha'")->fetchColumn();
    if (!$hasEntregaFecha) {
        $pdo->exec("ALTER TABLE pedidos ADD COLUMN entrega_fecha DATE NULL AFTER notas, ADD COLUMN entrega_franja VARCHAR(30) NULL AFTER entrega_fecha");
        msg("Migración <b>pedidos</b>: columnas entrega_fecha y entrega_franja agregadas", 'ok');
    }
} catch (Exception $e) {
    msg("Error migrando pedidos (entrega): " . htmlspecialchars($e->getMessage()), 'error');
}

// Migración: subtotal y envio en pedidos
try {
    $hasSubtotal = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND COLUMN_NAME = 'subtotal'")->fetchColumn();
    if (!$hasSubtotal) {
        $pdo->exec("ALTER TABLE pedidos ADD COLUMN subtotal DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER entrega_franja, ADD COLUMN envio DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER subtotal");
        // Inicializar subtotal con el valor actual de total (envio = 0)
        $pdo->exec("UPDATE pedidos SET subtotal = total WHERE subtotal = 0");
        msg("Migración <b>pedidos</b>: columnas subtotal y envio agregadas", 'ok');
    } else {
        msg("Columnas <b>subtotal, envio</b> ya existen en pedidos", 'info');
    }
} catch (Exception $e) {
    msg("Error migrando pedidos (subtotal/envio): " . htmlspecialchars($e->getMessage()), 'error');
}

// Migración: deposito_id en pedidos
try {
    $hasDepositoId = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND COLUMN_NAME = 'deposito_id'")->fetchColumn();
    if (!$hasDepositoId) {
        $pdo->exec("ALTER TABLE pedidos ADD COLUMN deposito_id INT UNSIGNED DEFAULT NULL AFTER total");
        msg("Migración <b>pedidos</b>: columna deposito_id agregada", 'ok');
    } else {
        msg("Columna <b>deposito_id</b> ya existe en pedidos", 'info');
    }
} catch (Exception $e) {
    msg("Error migrando pedidos (deposito_id): " . htmlspecialchars($e->getMessage()), 'error');
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pedido_items (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            pedido_id   INT UNSIGNED NOT NULL,
            producto_id INT UNSIGNED DEFAULT NULL,
            nombre      VARCHAR(120) NOT NULL,
            precio      DECIMAL(10,2) NOT NULL,
            cantidad    INT UNSIGNED NOT NULL DEFAULT 1,
            FOREIGN KEY (pedido_id)   REFERENCES pedidos(id)   ON DELETE CASCADE,
            FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    msg("Tabla <b>pedido_items</b> creada/verificada", 'ok');
} catch (Exception $e) {
    msg("Error creando tabla pedido_items: " . htmlspecialchars($e->getMessage()), 'error');
    $ok = false;
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS eventos (
            id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tipo        VARCHAR(40)  NOT NULL DEFAULT 'legado',
            actor_type  VARCHAR(20)  NOT NULL DEFAULT 'cliente',
            actor_id    INT UNSIGNED DEFAULT NULL,
            session_id  VARCHAR(64)  DEFAULT NULL,
            datos       JSON         NOT NULL DEFAULT (JSON_OBJECT()),
            ip          VARCHAR(45)  DEFAULT NULL,
            created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_tipo (tipo),
            INDEX idx_actor (actor_type, actor_id),
            INDEX idx_fecha (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    msg("Tabla <b>eventos</b> creada/verificada", 'ok');
} catch (Exception $e) {
    msg("Error creando tabla eventos: " . htmlspecialchars($e->getMessage()), 'error');
    $ok = false;
}

// ── Migraciones tabla eventos ──
// Paso 1: agregar columnas nuevas si aún no existen (tabla vieja sin tipo)
try {
    $pdo->query("SELECT tipo FROM eventos LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("ALTER TABLE eventos
        ADD COLUMN tipo       VARCHAR(40)  NOT NULL DEFAULT 'legado'  AFTER id,
        ADD COLUMN actor_type VARCHAR(20)  NOT NULL DEFAULT 'cliente' AFTER tipo,
        ADD COLUMN actor_id   INT UNSIGNED DEFAULT NULL               AFTER actor_type,
        ADD COLUMN session_id VARCHAR(64)  DEFAULT NULL               AFTER actor_id,
        ADD COLUMN datos      JSON         NOT NULL DEFAULT (JSON_OBJECT()) AFTER session_id,
        ADD COLUMN ip         VARCHAR(45)  DEFAULT NULL               AFTER datos,
        ADD INDEX idx_tipo (tipo),
        ADD INDEX idx_actor (actor_type, actor_id),
        ADD INDEX idx_fecha (created_at)
    ");
    // Rescatar datos históricos antes de eliminar columnas viejas
    $pdo->exec("
        UPDATE eventos SET
            actor_id  = NULLIF(cliente_id, 0),
            datos     = JSON_OBJECT('detalle', detalle),
            tipo      = CASE
                WHEN detalle LIKE 'Agregó al carrito%'  THEN 'carrito_agregar'
                WHEN detalle LIKE 'Quitó del carrito%'  THEN 'carrito_quitar'
                WHEN detalle LIKE 'Ingresó a:%'         THEN 'navegacion'
                ELSE 'legado'
            END
        WHERE tipo = 'legado'
    ");
    msg("Migración <b>eventos</b>: estructura nueva aplicada y datos históricos rescatados", 'ok');
}

// Paso 2: eliminar columnas obsoletas cliente_id y detalle si aún existen
try {
    $pdo->query("SELECT cliente_id FROM eventos LIMIT 1");
    $pdo->exec("ALTER TABLE eventos DROP COLUMN cliente_id");
    msg("Migración <b>eventos</b>: columna obsoleta <b>cliente_id</b> eliminada", 'ok');
} catch (Exception $e) { /* ya no existe */ }

try {
    $pdo->query("SELECT detalle FROM eventos LIMIT 1");
    $pdo->exec("ALTER TABLE eventos DROP COLUMN detalle");
    msg("Migración <b>eventos</b>: columna obsoleta <b>detalle</b> eliminada", 'ok');
} catch (Exception $e) { /* ya no existe */ }

// ── Tablas preparaciones / preparaciones_items (despacho con lector de código) ──
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS preparaciones (
            id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            numero        VARCHAR(20)  NOT NULL UNIQUE,
            pedido_id     INT UNSIGNED NOT NULL,
            estado        ENUM('en_curso','completa','parcial','cancelada') NOT NULL DEFAULT 'en_curso',
            usuario_id    INT UNSIGNED DEFAULT NULL,
            usuario_nombre VARCHAR(100) DEFAULT '',
            iniciada_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            finalizada_at TIMESTAMP    NULL DEFAULT NULL,
            notas         TEXT,
            created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            updated_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_pedido (pedido_id),
            INDEX idx_estado (estado),
            CONSTRAINT fk_prep_pedido FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS preparaciones_items (
            id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            preparacion_id       INT UNSIGNED NOT NULL,
            producto_id          INT UNSIGNED DEFAULT NULL,
            nombre               VARCHAR(160) NOT NULL,
            ean                  VARCHAR(40)  DEFAULT '',
            sku                  VARCHAR(40)  DEFAULT '',
            cantidad_solicitada  INT UNSIGNED NOT NULL DEFAULT 1,
            cantidad_escaneada   INT UNSIGNED NOT NULL DEFAULT 0,
            completo             TINYINT(1)   NOT NULL DEFAULT 0,
            INDEX idx_prep (preparacion_id),
            INDEX idx_ean (ean),
            INDEX idx_sku (sku),
            CONSTRAINT fk_prepi_prep FOREIGN KEY (preparacion_id) REFERENCES preparaciones(id) ON DELETE CASCADE,
            CONSTRAINT fk_prepi_prod FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    msg("Tablas <b>preparaciones</b> y <b>preparaciones_items</b> creadas/verificadas", 'ok');
} catch (Exception $e) {
    msg("Error creando tablas preparaciones: " . htmlspecialchars($e->getMessage()), 'error');
    $ok = false;
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS proveedores (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            nombre      VARCHAR(120) NOT NULL,
            domicilio   VARCHAR(255) DEFAULT '',
            correo      VARCHAR(150) DEFAULT NULL,
            lat         DECIMAL(10,7) DEFAULT NULL,
            lng         DECIMAL(10,7) DEFAULT NULL,
            created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            updated_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    msg("Tabla <b>proveedores</b> creada/verificada", 'ok');
} catch (Exception $e) {
    msg("Error creando tabla proveedores: " . htmlspecialchars($e->getMessage()), 'error');
    $ok = false;
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS compras (
            id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            numero        VARCHAR(20)  NOT NULL UNIQUE,
            proveedor_id  INT UNSIGNED DEFAULT NULL,
            proveedor     VARCHAR(120) NOT NULL,
            telefono      VARCHAR(40)  DEFAULT '',
            direccion     VARCHAR(255) DEFAULT '',
            notas         TEXT,
            total         DECIMAL(12,2) NOT NULL DEFAULT 0,
            estado        VARCHAR(30)  NOT NULL DEFAULT 'pendiente',
            created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            updated_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (proveedor_id) REFERENCES proveedores(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    msg("Tabla <b>compras</b> creada/verificada", 'ok');
} catch (Exception $e) {
    msg("Error creando tabla compras: " . htmlspecialchars($e->getMessage()), 'error');
    $ok = false;
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS compra_items (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            compra_id   INT UNSIGNED NOT NULL,
            producto_id INT UNSIGNED DEFAULT NULL,
            nombre      VARCHAR(120) NOT NULL,
            precio      DECIMAL(10,2) NOT NULL,
            cantidad    INT UNSIGNED NOT NULL DEFAULT 1,
            FOREIGN KEY (compra_id)   REFERENCES compras(id)   ON DELETE CASCADE,
            FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    msg("Tabla <b>compra_items</b> creada/verificada", 'ok');
} catch (Exception $e) {
    msg("Error creando tabla compra_items: " . htmlspecialchars($e->getMessage()), 'error');
    $ok = false;
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS configuracion (
            clave      VARCHAR(100) PRIMARY KEY,
            valor      TEXT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    msg("Tabla <b>configuracion</b> creada/verificada", 'ok');

    $cfgDefaults = [
        'pedido_minimo'          => '0',
        'centro_dist_lat'        => '',
        'centro_dist_lng'        => '',
        'precio_base_envio'      => '0',
        'precio_km'              => '0',
        'datarocket_url'         => '',
        'datarocket_apikey'      => '',
        'datarocket_proyecto'    => '',
        'datarocket_canal_email' => '',
        'datarocket_canal_wa'    => '',
        'datarocket_remitente'   => '',
        'datarocket_remite'      => '',
        'mp_access_token'        => '',
        'mp_public_key'          => '',
        'mp_app_url'             => '',
        'google_maps_key'        => '',
        'google_maps_key_admin'  => '',
        'jwt_secret_admin'       => '',
        'jwt_secret_delivery'    => '',
        'jwt_secret_app'         => '',
    ];
    $stmtCfg = $pdo->prepare("INSERT IGNORE INTO configuracion (clave, valor) VALUES (?, ?)");
    foreach ($cfgDefaults as $clave => $valor) {
        $stmtCfg->execute([$clave, $valor]);
    }
    msg("Configuración por defecto insertada (<b>" . count($cfgDefaults) . "</b> claves)", 'ok');

    // Generar JWT secrets aleatorios si están vacíos
    $stmtJwt = $pdo->prepare("UPDATE configuracion SET valor = ? WHERE clave = ? AND (valor = '' OR valor IS NULL)");
    foreach (['jwt_secret_admin', 'jwt_secret_delivery', 'jwt_secret_app'] as $jwtKey) {
        $stmtJwt->execute([bin2hex(random_bytes(32)), $jwtKey]);
        if ($stmtJwt->rowCount() > 0) {
            msg("JWT secret generado para <b>$jwtKey</b>", 'ok');
        } else {
            msg("JWT secret ya existe para <b>$jwtKey</b>", 'info');
        }
    }
} catch (Exception $e) {
    msg("Error creando tabla configuracion: " . htmlspecialchars($e->getMessage()), 'error');
    $ok = false;
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mensajes (
            id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            canal        ENUM('email','whatsapp') NOT NULL,
            destinatario VARCHAR(255) NOT NULL,
            destino      VARCHAR(255) NOT NULL DEFAULT '',
            asunto       VARCHAR(500) NOT NULL DEFAULT '',
            mensaje      TEXT        NOT NULL,
            estado       VARCHAR(50)  NOT NULL DEFAULT 'enviado',
            created_at   TIMESTAMP   DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    msg("Tabla <b>mensajes</b> creada/verificada", 'ok');
} catch (Exception $e) {
    msg("Error creando tabla mensajes: " . htmlspecialchars($e->getMessage()), 'error');
    $ok = false;
}

// ── 1b. Migraciones: agregar columnas faltantes ──────────────────
if ($ok) {
    // lat/lng → entrega_lat/entrega_lng + retiro_lat/retiro_lng en pedidos
    try {
        $hasEntregaLat = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND COLUMN_NAME = 'entrega_lat'")->fetchColumn();
        if (!$hasEntregaLat) {
            $hasLat = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND COLUMN_NAME = 'lat'")->fetchColumn();
            if ($hasLat) {
                $pdo->exec("ALTER TABLE pedidos CHANGE lat entrega_lat DECIMAL(10,7) DEFAULT NULL, CHANGE lng entrega_lng DECIMAL(10,7) DEFAULT NULL");
                msg("Migración <b>pedidos</b>: lat/lng renombradas a entrega_lat/entrega_lng", 'ok');
            } else {
                $pdo->exec("ALTER TABLE pedidos ADD COLUMN entrega_lat DECIMAL(10,7) DEFAULT NULL, ADD COLUMN entrega_lng DECIMAL(10,7) DEFAULT NULL");
                msg("Columnas <b>entrega_lat, entrega_lng</b> agregadas a pedidos", 'ok');
            }
        } else {
            msg("Columnas <b>entrega_lat, entrega_lng</b> ya existen en pedidos", 'info');
        }
        $hasRetiroLat = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND COLUMN_NAME = 'retiro_lat'")->fetchColumn();
        if (!$hasRetiroLat) {
            $pdo->exec("ALTER TABLE pedidos ADD COLUMN retiro_lat DECIMAL(10,7) DEFAULT NULL AFTER estado, ADD COLUMN retiro_lng DECIMAL(10,7) DEFAULT NULL AFTER retiro_lat");
            msg("Columnas <b>retiro_lat, retiro_lng</b> agregadas a pedidos", 'ok');
        } else {
            msg("Columnas <b>retiro_lat, retiro_lng</b> ya existen en pedidos", 'info');
        }
    } catch (Exception $e) {
        msg("Error migrando coordenadas de pedidos: " . htmlspecialchars($e->getMessage()), 'error');
    }

    // distancia_km en pedidos
    try {
        $pdo->query("SELECT distancia_km FROM pedidos LIMIT 1");
        msg("Columna <b>distancia_km</b> ya existe en pedidos", 'info');
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE pedidos ADD COLUMN distancia_km DECIMAL(8,2) DEFAULT NULL");
        msg("Columna <b>distancia_km</b> agregada a pedidos", 'ok');
    }

    // tiempo_min en pedidos
    try {
        $pdo->query("SELECT tiempo_min FROM pedidos LIMIT 1");
        msg("Columna <b>tiempo_min</b> ya existe en pedidos", 'info');
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE pedidos ADD COLUMN tiempo_min INT UNSIGNED DEFAULT NULL");
        msg("Columna <b>tiempo_min</b> agregada a pedidos", 'ok');
    }

    // cliente_id en pedidos
    try {
        $pdo->query("SELECT cliente_id FROM pedidos LIMIT 1");
        msg("Columna <b>cliente_id</b> ya existe en pedidos", 'info');
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE pedidos ADD COLUMN cliente_id INT UNSIGNED DEFAULT NULL AFTER numero");
        msg("Columna <b>cliente_id</b> agregada a pedidos", 'ok');
    }

    // peso_pieza en productos — eliminar si existe
    try {
        $pdo->query("SELECT peso_pieza FROM productos LIMIT 1");
        $pdo->exec("ALTER TABLE productos DROP COLUMN peso_pieza");
        msg("Columna obsoleta <b>peso_pieza</b> eliminada de productos", 'ok');
    } catch (Exception $e) {
        msg("Columna <b>peso_pieza</b> ya no existe en productos", 'info');
    }

    // contenido en productos
    try {
        $pdo->query("SELECT contenido FROM productos LIMIT 1");
        msg("Columna <b>contenido</b> ya existe en productos", 'info');
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE productos ADD COLUMN contenido VARCHAR(50) DEFAULT NULL AFTER imagen");
        msg("Columna <b>contenido</b> agregada a productos", 'ok');
    }

    // precio_compra y margen en productos
    try {
        $pdo->query("SELECT precio_compra FROM productos LIMIT 1");
        msg("Columnas <b>precio_compra, margen</b> ya existen en productos", 'info');
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE productos ADD COLUMN precio_compra DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER ean, ADD COLUMN margen DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER precio_compra");
        msg("Columnas <b>precio_compra, margen</b> agregadas a productos", 'ok');
    }

    // precio → precio_venta en productos
    try {
        $pdo->query("SELECT precio_venta FROM productos LIMIT 1");
        msg("Columna <b>precio_venta</b> ya existe en productos", 'info');
    } catch (Exception $e) {
        $hasPrecio = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos' AND COLUMN_NAME = 'precio'")->fetchColumn();
        if ($hasPrecio) {
            $pdo->exec("ALTER TABLE productos CHANGE COLUMN precio precio_venta DECIMAL(10,2) NOT NULL DEFAULT 0");
            msg("Migración <b>productos</b>: columna precio renombrada a precio_venta", 'ok');
        } else {
            $pdo->exec("ALTER TABLE productos ADD COLUMN precio_venta DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER margen");
            msg("Columna <b>precio_venta</b> agregada a productos", 'ok');
        }
    }

    // stock_actual, stock_minimo, stock_recomendado en productos
    try {
        $pdo->query("SELECT stock_actual FROM productos LIMIT 1");
        msg("Columnas <b>stock_actual, stock_minimo, stock_recomendado</b> ya existen en productos", 'info');
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE productos ADD COLUMN stock_actual INT NOT NULL DEFAULT 1, ADD COLUMN stock_minimo INT NOT NULL DEFAULT 0, ADD COLUMN stock_recomendado INT NOT NULL DEFAULT 3");
        msg("Columnas <b>stock_actual, stock_minimo, stock_recomendado</b> agregadas a productos", 'ok');
    }

    // stock_comprometido en productos
    try {
        $pdo->query("SELECT stock_comprometido FROM productos LIMIT 1");
        msg("Columna <b>stock_comprometido</b> ya existe en productos", 'info');
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE productos ADD COLUMN stock_comprometido INT NOT NULL DEFAULT 0 AFTER stock_actual");
        msg("Columna <b>stock_comprometido</b> agregada a productos", 'ok');
    }

    // Eliminar columna stock obsoleta (ahora se usa stock_actual)
    try {
        $pdo->query("SELECT stock FROM productos LIMIT 1");
        $pdo->exec("ALTER TABLE productos DROP COLUMN stock");
        msg("Columna obsoleta <b>stock</b> eliminada de productos", 'ok');
    } catch (Exception $e) {
        msg("Columna <b>stock</b> ya no existe en productos", 'info');
    }

    // proveedor_id en productos
    try {
        $pdo->query("SELECT proveedor_id FROM productos LIMIT 1");
        msg("Columna <b>proveedor_id</b> ya existe en productos", 'info');
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE productos ADD COLUMN proveedor_id INT UNSIGNED DEFAULT NULL AFTER stock_recomendado");
        msg("Columna <b>proveedor_id</b> agregada a productos", 'ok');
    }

    // correo en clientes
    try {
        $pdo->query("SELECT correo FROM clientes LIMIT 1");
        msg("Columna <b>correo</b> ya existe en clientes", 'info');
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE clientes ADD COLUMN correo VARCHAR(150) DEFAULT NULL");
        msg("Columna <b>correo</b> agregada a clientes", 'ok');
    }

    // lat, lng en clientes
    try {
        $pdo->query("SELECT lat FROM clientes LIMIT 1");
        msg("Columnas <b>lat, lng</b> ya existen en clientes", 'info');
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE clientes ADD COLUMN lat DECIMAL(10,7) DEFAULT NULL, ADD COLUMN lng DECIMAL(10,7) DEFAULT NULL");
        msg("Columnas <b>lat, lng</b> agregadas a clientes", 'ok');
    }

    // contrasena, clave en clientes
    try {
        $pdo->query("SELECT contrasena FROM clientes LIMIT 1");
        msg("Columnas <b>contrasena, clave</b> ya existen en clientes", 'info');
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE clientes ADD COLUMN contrasena VARCHAR(100) NOT NULL DEFAULT '' AFTER correo, ADD COLUMN clave VARCHAR(100) NOT NULL DEFAULT '' AFTER contrasena");
        msg("Columnas <b>contrasena, clave</b> agregadas a clientes", 'ok');
    }

    // destino en mensajes
    try {
        $pdo->query("SELECT destino FROM mensajes LIMIT 1");
        msg("Columna <b>destino</b> ya existe en mensajes", 'info');
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE mensajes ADD COLUMN destino VARCHAR(255) NOT NULL DEFAULT '' AFTER destinatario");
        msg("Columna <b>destino</b> agregada a mensajes", 'ok');
    }
}


try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS carritos (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            usuario_id  INT UNSIGNED DEFAULT NULL,
            estado      ENUM('activo','abandonado','exitoso') NOT NULL DEFAULT 'activo',
            total       DECIMAL(12,2) NOT NULL DEFAULT 0,
            deposito_id INT UNSIGNED DEFAULT NULL,
            created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            updated_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (usuario_id) REFERENCES clientes(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    msg("Tabla <b>carritos</b> creada/verificada", 'ok');
} catch (Exception $e) {
    msg("Error creando tabla carritos: " . htmlspecialchars($e->getMessage()), 'error');
    $ok = false;
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS carritos_items (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            carrito_id  INT UNSIGNED NOT NULL,
            producto_id INT UNSIGNED DEFAULT NULL,
            nombre      VARCHAR(120) NOT NULL,
            precio      DECIMAL(10,2) NOT NULL,
            cantidad    INT UNSIGNED NOT NULL DEFAULT 1,
            FOREIGN KEY (carrito_id)  REFERENCES carritos(id)  ON DELETE CASCADE,
            FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    msg("Tabla <b>carritos_items</b> creada/verificada", 'ok');
} catch (Exception $e) {
    msg("Error creando tabla carritos_items: " . htmlspecialchars($e->getMessage()), 'error');
    $ok = false;
}

// Migración: session_id en carritos
try {
    $pdo->query("SELECT session_id FROM carritos LIMIT 1");
    msg("Columna <b>session_id</b> ya existe en carritos", 'info');
} catch (Exception $e) {
    $pdo->exec("ALTER TABLE carritos ADD COLUMN session_id VARCHAR(64) NOT NULL DEFAULT '' AFTER usuario_id");
    msg("Columna <b>session_id</b> agregada a carritos", 'ok');
}

// Migración: deposito_id en carritos
try {
    $pdo->query("SELECT deposito_id FROM carritos LIMIT 1");
    msg("Columna <b>deposito_id</b> ya existe en carritos", 'info');
} catch (Exception $e) {
    $pdo->exec("ALTER TABLE carritos ADD COLUMN deposito_id INT UNSIGNED DEFAULT NULL AFTER total");
    msg("Columna <b>deposito_id</b> agregada a carritos", 'ok');
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS repartidores (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            nombre      VARCHAR(120) NOT NULL,
            correo      VARCHAR(150) DEFAULT NULL,
            celular     VARCHAR(40)  DEFAULT '',
            vehiculo    ENUM('bicicleta','moto','auto','furgon','camioneta','camion') DEFAULT NULL,
            direccion   VARCHAR(255) DEFAULT '',
            contrasena  VARCHAR(100) NOT NULL DEFAULT '',
            clave       VARCHAR(100) NOT NULL DEFAULT '',
            lat         DECIMAL(10,7) DEFAULT NULL,
            lng         DECIMAL(10,7) DEFAULT NULL,
            created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            updated_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    msg("Tabla <b>repartidores</b> creada/verificada", 'ok');
} catch (Exception $e) {
    msg("Error creando tabla repartidores: " . htmlspecialchars($e->getMessage()), 'error');
    $ok = false;
}

// ── Tabla cuentas (plan de cuentas contable) ──
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cuentas (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            codigo      VARCHAR(20)  NOT NULL UNIQUE,
            nombre      VARCHAR(160) NOT NULL,
            tipo        ENUM('activo','pasivo','patrimonio','ingreso','egreso') NOT NULL,
            parent_id   INT UNSIGNED DEFAULT NULL,
            nivel       TINYINT UNSIGNED NOT NULL DEFAULT 1,
            imputable   TINYINT(1)   NOT NULL DEFAULT 1,
            naturaleza  ENUM('deudora','acreedora') NOT NULL,
            descripcion TEXT         DEFAULT NULL,
            activa      TINYINT(1)   NOT NULL DEFAULT 1,
            created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            updated_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_parent (parent_id),
            INDEX idx_tipo (tipo),
            INDEX idx_codigo (codigo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    msg("Tabla <b>cuentas</b> creada/verificada", 'ok');
} catch (Exception $e) {
    msg("Error creando tabla cuentas: " . htmlspecialchars($e->getMessage()), 'error');
    $ok = false;
}

// ── Tablas asientos / asientos_detalle (libro diario) ──
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS asientos (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            numero      INT UNSIGNED NOT NULL,
            fecha       DATE         NOT NULL,
            descripcion VARCHAR(255) NOT NULL,
            total       DECIMAL(14,2) NOT NULL DEFAULT 0,
            created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            updated_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_numero (numero),
            INDEX idx_fecha (fecha)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS asientos_detalle (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            asiento_id  INT UNSIGNED NOT NULL,
            cuenta_id   INT UNSIGNED NOT NULL,
            debe        DECIMAL(14,2) NOT NULL DEFAULT 0,
            haber       DECIMAL(14,2) NOT NULL DEFAULT 0,
            descripcion VARCHAR(255) DEFAULT NULL,
            orden       TINYINT UNSIGNED NOT NULL DEFAULT 0,
            INDEX idx_asiento (asiento_id),
            INDEX idx_cuenta (cuenta_id),
            CONSTRAINT fk_asd_asiento FOREIGN KEY (asiento_id) REFERENCES asientos(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    msg("Tablas <b>asientos</b> y <b>asientos_detalle</b> creadas/verificadas", 'ok');
} catch (Exception $e) {
    msg("Error creando tablas asientos: " . htmlspecialchars($e->getMessage()), 'error');
    $ok = false;
}

// ── Tabla usuarios (backoffice) ──
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS usuarios (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            nombre     VARCHAR(100) NOT NULL,
            correo     VARCHAR(255) NOT NULL DEFAULT '',
            celular    VARCHAR(50)  NOT NULL DEFAULT '',
            contrasena VARCHAR(255) NOT NULL DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    try {
        $pdo->exec("ALTER TABLE usuarios CHANGE usuario nombre VARCHAR(100) NOT NULL");
    } catch (Exception $e) { /* columna ya renombrada o no existe */ }
    msg("Tabla <b>usuarios</b> creada/verificada", 'ok');
} catch (Exception $e) {
    msg("Error creando tabla usuarios: " . htmlspecialchars($e->getMessage()), 'error');
}

// Migración: repartidor_id en pedidos
try {
    $pdo->query("SELECT repartidor_id FROM pedidos LIMIT 1");
    msg("Columna <b>repartidor_id</b> ya existe en pedidos", 'info');
} catch (Exception $e) {
    $pdo->exec("ALTER TABLE pedidos ADD COLUMN repartidor_id INT UNSIGNED DEFAULT NULL AFTER cliente_id");
    msg("Columna <b>repartidor_id</b> agregada a pedidos", 'ok');
}

// Migración: repartidor_tarifa en pedidos (renombrado desde repartidor_pago)
try {
    $pdo->query("SELECT repartidor_tarifa FROM pedidos LIMIT 1");
    msg("Columna <b>repartidor_tarifa</b> ya existe en pedidos", 'info');
} catch (Exception $e) {
    // Si existe con el nombre viejo, renombrar; si no, crear directamente
    try {
        $pdo->query("SELECT repartidor_pago FROM pedidos LIMIT 1");
        $pdo->exec("ALTER TABLE pedidos CHANGE repartidor_pago repartidor_tarifa DECIMAL(12,2) DEFAULT NULL");
        msg("Columna <b>repartidor_pago</b> renombrada a <b>repartidor_tarifa</b> en pedidos", 'ok');
    } catch (Exception $e2) {
        $pdo->exec("ALTER TABLE pedidos ADD COLUMN repartidor_tarifa DECIMAL(12,2) DEFAULT NULL AFTER repartidor_id");
        msg("Columna <b>repartidor_tarifa</b> agregada a pedidos", 'ok');
    }
}

// ── Tabla push_subscriptions (Web Push) ──
try {
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
    msg("Tabla <b>push_subscriptions</b> creada/verificada", 'ok');
} catch (Exception $e) {
    msg("Error creando tabla push_subscriptions: " . htmlspecialchars($e->getMessage()), 'error');
}

// ── Tabla notificaciones ──
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS notificaciones (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            actor_type  ENUM('repartidor','cliente','usuario') NOT NULL,
            actor_id    INT UNSIGNED NOT NULL,
            titulo      VARCHAR(200) NOT NULL,
            cuerpo      TEXT NOT NULL,
            data        TEXT NULL,
            estado      VARCHAR(20) NOT NULL DEFAULT 'enviado',
            error       VARCHAR(500) NOT NULL DEFAULT '',
            leida       TINYINT(1) NOT NULL DEFAULT 0,
            leida_at    TIMESTAMP NULL DEFAULT NULL,
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_actor_unread (actor_type, actor_id, leida),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    msg("Tabla <b>notificaciones</b> creada/verificada", 'ok');
} catch (Exception $e) {
    msg("Error creando tabla notificaciones: " . htmlspecialchars($e->getMessage()), 'error');
}

// ── Migraciones: metodo_pago y estado_pago en pedidos ──
try {
    $pdo->query("SELECT metodo_pago FROM pedidos LIMIT 1");
    msg("Columnas <b>metodo_pago, estado_pago</b> ya existen en pedidos", 'info');
} catch (Exception $e) {
    $pdo->exec("ALTER TABLE pedidos
        ADD COLUMN metodo_pago ENUM('efectivo','mercadopago') DEFAULT NULL,
        ADD COLUMN estado_pago ENUM('pendiente','pagado','parcial','reembolsado') NOT NULL DEFAULT 'pendiente'");
    msg("Columnas <b>metodo_pago, estado_pago</b> agregadas a pedidos", 'ok');
}

// ── Tabla pagos ──
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pagos (
            id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            pedido_id         INT UNSIGNED NOT NULL,
            metodo            ENUM('efectivo','mercadopago') NOT NULL,
            monto             DECIMAL(12,2) NOT NULL,
            estado            ENUM('pendiente','aprobado','rechazado','reembolsado') NOT NULL DEFAULT 'pendiente',
            mp_preference_id  VARCHAR(100) DEFAULT NULL,
            mp_payment_id     VARCHAR(100) DEFAULT NULL,
            mp_status         VARCHAR(50)  DEFAULT NULL,
            recibido_por      VARCHAR(80)  DEFAULT NULL,
            notas             TEXT,
            created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE,
            INDEX idx_pedido (pedido_id),
            INDEX idx_metodo (metodo),
            INDEX idx_estado (estado),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    msg("Tabla <b>pagos</b> creada/verificada", 'ok');
} catch (Exception $e) {
    msg("Error creando tabla pagos: " . htmlspecialchars($e->getMessage()), 'error');
    $ok = false;
}

// ── Generar par de claves VAPID una sola vez ──
try {
    $stmtVapid = $pdo->query("SELECT clave, valor FROM configuracion WHERE clave IN ('vapid_public_key','vapid_private_key','vapid_subject')");
    $vapid = [];
    foreach ($stmtVapid->fetchAll() as $r) { $vapid[$r['clave']] = $r['valor']; }

    if (empty($vapid['vapid_public_key']) || empty($vapid['vapid_private_key'])) {
        $keyRes = openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        if ($keyRes === false) {
            throw new Exception('openssl_pkey_new falló — ¿está disponible la extensión openssl con curvas EC?');
        }
        $details = openssl_pkey_get_details($keyRes);

        // Formato raw para Web Push (RFC 8291):
        //   pública  = 0x04 || X(32) || Y(32)       — 65 bytes uncompressed
        //   privada  = d (32 bytes, big-endian)
        $x = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
        $d = str_pad($details['ec']['d'], 32, "\x00", STR_PAD_LEFT);
        $publicRaw  = "\x04" . $x . $y;
        $privateRaw = $d;

        $b64u = function ($bin) { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); };

        $pdo->prepare("INSERT INTO configuracion (clave, valor) VALUES (?, ?)
                       ON DUPLICATE KEY UPDATE valor = VALUES(valor)")
            ->execute(['vapid_public_key',  $b64u($publicRaw)]);
        $pdo->prepare("INSERT INTO configuracion (clave, valor) VALUES (?, ?)
                       ON DUPLICATE KEY UPDATE valor = VALUES(valor)")
            ->execute(['vapid_private_key', $b64u($privateRaw)]);

        // Subject por defecto — el operador puede cambiarlo desde Configuración si quiere
        if (empty($vapid['vapid_subject'])) {
            $pdo->prepare("INSERT IGNORE INTO configuracion (clave, valor) VALUES (?, ?)")
                ->execute(['vapid_subject', 'mailto:admin@repo.com.ar']);
        }

        msg("Claves <b>VAPID</b> generadas y guardadas en configuración", 'ok');
    } else {
        msg("Claves <b>VAPID</b> ya existen — no se regeneran", 'info');
    }
} catch (Exception $e) {
    msg("Error generando claves VAPID: " . htmlspecialchars($e->getMessage()), 'error');
}

endif; // $ok
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalación — Repo DB</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #0f172a; color: #e2e8f0; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .card { background: #1e293b; border-radius: 16px; padding: 32px; max-width: 600px; width: 90%; box-shadow: 0 25px 50px rgba(0,0,0,.3); }
        h1 { font-size: 1.4rem; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .log { background: #0f172a; border-radius: 10px; padding: 16px; font-family: 'Cascadia Code', 'Fira Code', monospace; font-size: 0.9rem; line-height: 1.6; }
        .footer { margin-top: 20px; text-align: center; color: #64748b; font-size: 0.8rem; }
        .footer a { color: #60a5fa; text-decoration: none; }
    </style>
</head>
<body>
    <div class="card">
        <h1>🛠️ Instalación de Base de Datos</h1>
        <div class="log">
            <?= implode('', $log) ?>
        </div>
        <div class="footer">
            <p>Eliminar este archivo después de la instalación por seguridad.</p>
            <p style="margin-top:8px"><a href="../repo-app/">← Ir a la App</a> &nbsp;|&nbsp; <a href="../repo-admin/">Ir al Admin →</a></p>
        </div>
    </div>
</body>
</html>
