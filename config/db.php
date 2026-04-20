<?php
/**
 * Configuración de conexión a base de datos RDS (MySQL)
 * ¡No subir este archivo al repositorio! Agregar a .gitignore
 */

define('DB_HOST', 'oxford.databox.net.ar');
define('DB_PORT', 3306);
define('DB_NAME', 'lider');
define('DB_USER', 'admin');
define('DB_PASS', 'OVfEqi2GdbD0L1zHNC8M7Z5039I3Zgyd');
define('DB_CHARSET', 'utf8mb4');

/**
 * Obtener conexión PDO
 */
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}
