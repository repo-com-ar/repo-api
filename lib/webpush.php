<?php
/**
 * Web Push — implementación pura en PHP (sin dependencias externas).
 *
 * Cubre:
 *   - Generación de JWT VAPID (ES256, RFC 8292)
 *   - Cifrado de payload aes128gcm (RFC 8291)
 *   - Envío HTTP al endpoint del push service (FCM / Mozilla AutoPush / web.push.apple.com)
 *
 * Requiere:
 *   - ext-openssl con curva prime256v1 (universal)
 *   - PHP 7.3+ (openssl_pkey_derive)
 *
 * API principal:
 *   webpush_send(array $sub, string $payload, array $vapid, int $ttl = 2419200): array
 *     $sub   = ['endpoint' => '...', 'p256dh' => 'b64url', 'auth' => 'b64url']
 *     $vapid = ['public' => 'b64url', 'private' => 'b64url', 'subject' => 'mailto:...']
 *     return = ['status' => 201, 'body' => '...', 'error' => '']
 */

// ─────────────────────────────────────────────────────────────────────
// Utilidades base
// ─────────────────────────────────────────────────────────────────────

function webpush_b64u_encode(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function webpush_b64u_decode(string $str): string {
    $pad = strlen($str) % 4;
    if ($pad) { $str .= str_repeat('=', 4 - $pad); }
    return base64_decode(strtr($str, '-_', '+/'));
}

/** HKDF-Extract (RFC 5869) — HMAC-SHA256(salt, IKM). */
function webpush_hkdf_extract(string $salt, string $ikm): string {
    return hash_hmac('sha256', $ikm, $salt, true);
}

/** HKDF-Expand (RFC 5869) — expande PRK a $len bytes con $info como contexto. */
function webpush_hkdf_expand(string $prk, string $info, int $len): string {
    $output = '';
    $t      = '';
    $n      = (int)ceil($len / 32);
    for ($i = 1; $i <= $n; $i++) {
        $t = hash_hmac('sha256', $t . $info . chr($i), $prk, true);
        $output .= $t;
    }
    return substr($output, 0, $len);
}

/**
 * Convierte firma ECDSA en formato DER (lo que devuelve openssl_sign)
 * a "raw" r||s (64 bytes) que exige JWT ES256.
 */
function webpush_der_to_raw_sig(string $der): string {
    // DER: 30 <len> 02 <r_len> <r> 02 <s_len> <s>
    $offset = 0;
    if (ord($der[$offset++]) !== 0x30) { throw new Exception('DER inválido: falta SEQUENCE'); }
    $seqLen = ord($der[$offset++]);
    if ($seqLen & 0x80) {
        $n = $seqLen & 0x7F;
        $seqLen = 0;
        for ($i = 0; $i < $n; $i++) { $seqLen = ($seqLen << 8) | ord($der[$offset++]); }
    }
    if (ord($der[$offset++]) !== 0x02) { throw new Exception('DER inválido: falta INTEGER r'); }
    $rLen = ord($der[$offset++]);
    $r = substr($der, $offset, $rLen);
    $offset += $rLen;
    if (ord($der[$offset++]) !== 0x02) { throw new Exception('DER inválido: falta INTEGER s'); }
    $sLen = ord($der[$offset++]);
    $s = substr($der, $offset, $sLen);

    // ASN.1 agrega 0x00 al frente si el MSB es 1 (para mantener signo positivo).
    // Removerlo y padear a 32 bytes.
    $r = ltrim($r, "\x00");
    $s = ltrim($s, "\x00");
    $r = str_pad($r, 32, "\x00", STR_PAD_LEFT);
    $s = str_pad($s, 32, "\x00", STR_PAD_LEFT);

    return $r . $s;
}

/**
 * Envuelve un punto EC P-256 crudo (65 bytes: 0x04 || X || Y) en un
 * SubjectPublicKeyInfo PEM para que openssl_pkey_get_public() lo acepte.
 */
function webpush_ec_p256_raw_to_pem(string $rawPoint65): string {
    if (strlen($rawPoint65) !== 65 || $rawPoint65[0] !== "\x04") {
        throw new Exception('EC point debe ser 65 bytes uncompressed (0x04 || X || Y)');
    }
    // SPKI DER prefix para EC P-256, terminado justo antes del punto crudo
    //   30 59                                 SEQUENCE 89 bytes
    //     30 13                               SEQUENCE 19 bytes (AlgorithmIdentifier)
    //       06 07 2a 86 48 ce 3d 02 01        OID id-ecPublicKey
    //       06 08 2a 86 48 ce 3d 03 01 07     OID secp256r1
    //     03 42 00                            BIT STRING 66 bytes, 0 unused bits
    //       (sigue el punto de 65 bytes)
    $prefix = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200');
    $der    = $prefix . $rawPoint65;
    return "-----BEGIN PUBLIC KEY-----\n"
         . chunk_split(base64_encode($der), 64, "\n")
         . "-----END PUBLIC KEY-----\n";
}

/**
 * Construye un PEM de clave privada EC P-256 a partir del escalar crudo d (32 bytes)
 * y los componentes públicos X, Y (32 bytes cada uno), en formato SEC1 (RFC 5915).
 */
function webpush_ec_p256_priv_to_pem(string $d32, string $x32, string $y32): string {
    // SEC1 ECPrivateKey:
    //   SEQUENCE {
    //     INTEGER 1,                       -- version
    //     OCTET STRING d (32 bytes),
    //     [0] OID secp256r1,
    //     [1] BIT STRING (0x04 || X || Y)
    //   }
    $version = "\x02\x01\x01";                                    // INTEGER 1
    $privOct = "\x04\x20" . $d32;                                 // OCTET STRING (32)
    $curveOidContent = hex2bin('06082a8648ce3d030107');           // OID secp256r1
    $paramsTagged = "\xA0" . chr(strlen($curveOidContent)) . $curveOidContent;
    $pubPoint = "\x00\x04" . $x32 . $y32;                         // BIT STRING: 0 unused + uncompressed
    $pubBitStr = "\x03" . chr(strlen($pubPoint)) . $pubPoint;
    $pubTagged = "\xA1" . chr(strlen($pubBitStr)) . $pubBitStr;
    $seqBody   = $version . $privOct . $paramsTagged . $pubTagged;
    $der       = "\x30" . chr(strlen($seqBody)) . $seqBody;
    return "-----BEGIN EC PRIVATE KEY-----\n"
         . chunk_split(base64_encode($der), 64, "\n")
         . "-----END EC PRIVATE KEY-----\n";
}

// ─────────────────────────────────────────────────────────────────────
// VAPID: firmar JWT ES256
// ─────────────────────────────────────────────────────────────────────

/**
 * Firma un JWT VAPID para el endpoint dado.
 *
 * @param string $endpoint         URL completa del endpoint
 * @param string $subject          'mailto:...' o 'https://...'
 * @param string $vapidPublicB64u  clave pública VAPID (raw 65 bytes, b64url)
 * @param string $vapidPrivateB64u clave privada VAPID (raw 32 bytes, b64url)
 * @return array ['jwt' => '...', 'public' => '<vapid_public_b64u>']
 */
function webpush_vapid_sign(string $endpoint, string $subject, string $vapidPublicB64u, string $vapidPrivateB64u): array {
    $parts = parse_url($endpoint);
    $audience = $parts['scheme'] . '://' . $parts['host'];
    if (!empty($parts['port'])) { $audience .= ':' . $parts['port']; }

    $header  = ['typ' => 'JWT', 'alg' => 'ES256'];
    $payload = ['aud' => $audience, 'exp' => time() + 12 * 3600, 'sub' => $subject];

    $headerB64  = webpush_b64u_encode(json_encode($header, JSON_UNESCAPED_SLASHES));
    $payloadB64 = webpush_b64u_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));
    $signingInput = $headerB64 . '.' . $payloadB64;

    // Reconstruir PEM de clave privada a partir de los bytes raw
    $publicRaw = webpush_b64u_decode($vapidPublicB64u);
    if (strlen($publicRaw) !== 65 || $publicRaw[0] !== "\x04") {
        throw new Exception('VAPID public key: se espera 65 bytes uncompressed');
    }
    $privateRaw = webpush_b64u_decode($vapidPrivateB64u);
    if (strlen($privateRaw) !== 32) {
        throw new Exception('VAPID private key: se esperan 32 bytes');
    }
    $x = substr($publicRaw, 1, 32);
    $y = substr($publicRaw, 33, 32);
    $pemPriv = webpush_ec_p256_priv_to_pem($privateRaw, $x, $y);

    $privRes = openssl_pkey_get_private($pemPriv);
    if ($privRes === false) {
        throw new Exception('No se pudo cargar clave privada VAPID: ' . openssl_error_string());
    }
    $derSig = '';
    if (!openssl_sign($signingInput, $derSig, $privRes, OPENSSL_ALGO_SHA256)) {
        throw new Exception('openssl_sign falló: ' . openssl_error_string());
    }
    $rawSig = webpush_der_to_raw_sig($derSig);

    $jwt = $signingInput . '.' . webpush_b64u_encode($rawSig);
    return ['jwt' => $jwt, 'public' => $vapidPublicB64u];
}

// ─────────────────────────────────────────────────────────────────────
// Payload encryption (RFC 8291, content-encoding: aes128gcm)
// ─────────────────────────────────────────────────────────────────────

/**
 * Deriva el secreto compartido ECDH (32 bytes) entre una clave pública PEM
 * y una clave privada (resource o PEM).
 *
 * Usa openssl_pkey_derive() cuando está disponible (PHP 7.3+); si no, cae en
 * un fallback vía shell a `openssl pkeyutl -derive` que funciona en cualquier
 * PHP 7.x mientras el hosting permita shell_exec y tenga el binario openssl.
 */
function webpush_ecdh_derive(string $peerPubPem, $privKeyResource): string {
    if (function_exists('openssl_pkey_derive')) {
        $shared = openssl_pkey_derive($peerPubPem, $privKeyResource, 32);
        if ($shared === false) {
            throw new Exception('openssl_pkey_derive falló: ' . openssl_error_string());
        }
        return $shared;
    }

    if (!function_exists('shell_exec')) {
        throw new Exception(
            'Este servidor tiene PHP < 7.3 (sin openssl_pkey_derive) y shell_exec está deshabilitado. ' .
            'Subí la versión de PHP a 7.4 o superior desde el panel del hosting.'
        );
    }

    $privPem = '';
    if (!openssl_pkey_export($privKeyResource, $privPem)) {
        throw new Exception('No se pudo exportar clave privada efímera a PEM');
    }

    $tmp      = sys_get_temp_dir();
    $privFile = tempnam($tmp, 'wp_priv_');
    $peerFile = tempnam($tmp, 'wp_peer_');
    $outFile  = tempnam($tmp, 'wp_out_');
    try {
        file_put_contents($privFile, $privPem);
        file_put_contents($peerFile, $peerPubPem);
        $cmd = sprintf('openssl pkeyutl -derive -inkey %s -peerkey %s -out %s 2>&1',
            escapeshellarg($privFile),
            escapeshellarg($peerFile),
            escapeshellarg($outFile));
        $output = shell_exec($cmd);
        $shared = @file_get_contents($outFile);
        if ($shared === false || strlen($shared) !== 32) {
            throw new Exception('ECDH fallback falló (' . strlen((string)$shared) . ' bytes): ' . trim((string)$output));
        }
        return $shared;
    } finally {
        @unlink($privFile); @unlink($peerFile); @unlink($outFile);
    }
}

/**
 * Cifra el payload para un destinatario dado sus claves p256dh + auth.
 *
 * @param string $payload   texto plano a entregar (típicamente JSON)
 * @param string $clientPub 65 bytes raw (uncompressed EC point)
 * @param string $clientAuth 16 bytes
 * @return string cuerpo binario listo para mandar por HTTP
 *                (header aes128gcm + ciphertext + tag)
 */
function webpush_encrypt_payload(string $payload, string $clientPub, string $clientAuth): string {
    if (strlen($clientPub) !== 65 || $clientPub[0] !== "\x04") {
        throw new Exception('p256dh inválido');
    }
    if (strlen($clientAuth) !== 16) {
        throw new Exception('auth inválido (se esperan 16 bytes)');
    }

    // 1. Par efímero del servidor (AS)
    $asKey = openssl_pkey_new([
        'curve_name'       => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
    ]);
    if ($asKey === false) {
        throw new Exception('No se pudo generar par efímero: ' . openssl_error_string());
    }
    $asDetails = openssl_pkey_get_details($asKey);
    $asX = str_pad($asDetails['ec']['x'], 32, "\x00", STR_PAD_LEFT);
    $asY = str_pad($asDetails['ec']['y'], 32, "\x00", STR_PAD_LEFT);
    $asPub65 = "\x04" . $asX . $asY;

    // 2. ECDH con la clave pública del cliente
    $clientPem = webpush_ec_p256_raw_to_pem($clientPub);
    $shared    = webpush_ecdh_derive($clientPem, $asKey);

    // 3. HKDF para obtener IKM' (RFC 8291 §3.3)
    $prkKey  = webpush_hkdf_extract($clientAuth, $shared);
    $keyInfo = "WebPush: info\x00" . $clientPub . $asPub65;
    $ikm     = webpush_hkdf_expand($prkKey, $keyInfo, 32);

    // 4. Derivar CEK y NONCE
    $salt  = random_bytes(16);
    $prk   = webpush_hkdf_extract($salt, $ikm);
    $cek   = webpush_hkdf_expand($prk, "Content-Encoding: aes128gcm\x00", 16);
    $nonce = webpush_hkdf_expand($prk, "Content-Encoding: nonce\x00",    12);

    // 5. Padding: 1 byte 0x02 indica "último (y único) record"
    $padded = $payload . "\x02";

    // 6. AES-128-GCM
    $tag = '';
    $ciphertext = openssl_encrypt($padded, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($ciphertext === false) {
        throw new Exception('openssl_encrypt falló: ' . openssl_error_string());
    }

    // 7. Header de content-encoding: aes128gcm
    //    salt(16) || rs(4 BE) || idlen(1) || keyid(idlen bytes = 65)
    $rs = 4096;
    $header = $salt
            . pack('N', $rs)
            . chr(65)
            . $asPub65;

    return $header . $ciphertext . $tag;
}

// ─────────────────────────────────────────────────────────────────────
// Envío HTTP
// ─────────────────────────────────────────────────────────────────────

/**
 * Envía un push a un endpoint.
 *
 * @param array $sub   ['endpoint'=>..., 'p256dh'=>b64url, 'auth'=>b64url]
 * @param string $payload
 * @param array $vapid ['public'=>b64url, 'private'=>b64url, 'subject'=>'mailto:...']
 * @param int   $ttl   segundos que el push service puede retener el mensaje
 * @return array ['status'=>int, 'body'=>string, 'error'=>string]
 */
function webpush_send(array $sub, string $payload, array $vapid, int $ttl = 2419200): array {
    try {
        if (!function_exists('curl_init')) {
            throw new Exception('La extensión PHP curl no está habilitada en este servidor');
        }
        // openssl_pkey_derive es preferible (PHP 7.3+); si no está, webpush_ecdh_derive()
        // cae automáticamente al fallback por shell (openssl pkeyutl -derive).

        $endpoint   = $sub['endpoint'];
        $clientPub  = webpush_b64u_decode($sub['p256dh']);
        $clientAuth = webpush_b64u_decode($sub['auth']);

        $body = $payload === ''
            ? ''
            : webpush_encrypt_payload($payload, $clientPub, $clientAuth);

        $v = webpush_vapid_sign($endpoint, $vapid['subject'] ?? 'mailto:admin@example.com',
                                $vapid['public'], $vapid['private']);

        $headers = [
            'TTL: ' . $ttl,
            'Authorization: vapid t=' . $v['jwt'] . ', k=' . $v['public'],
            'Content-Length: ' . strlen($body),
        ];
        if ($body !== '') {
            $headers[] = 'Content-Type: application/octet-stream';
            $headers[] = 'Content-Encoding: aes128gcm';
        }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $respBody = curl_exec($ch);
        $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        return [
            'status' => $status,
            'body'   => $respBody === false ? '' : $respBody,
            'error'  => $err ?: '',
        ];
    } catch (Throwable $e) {
        return ['status' => 0, 'body' => '', 'error' => $e->getMessage()];
    }
}
