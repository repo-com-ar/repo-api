<?php
/**
 * Cron — Recalcula productos con stock y el flag activa por categoría.
 *
 * Modelo: 2 niveles. Raíz (parent_id NULL) agrupa subcategorías (parent_id != NULL).
 * Convención: los productos se asignan a subcategorías; el conteo de una raíz es
 * la suma de sus subcategorías (más cualquier producto directo histórico).
 *
 * 1. productos en cada categoría = COUNT(productos con stock_actual > 0 directamente
 *    asignados a ella) + si es raíz, suma de productos de sus subcategorías.
 * 2. activa = 1 si productos > 0, 0 si no.
 *
 * Expuesto en: https://api.repo.com.ar/cron/categorias_productos
 */
header('Content-Type: text/plain');

require_once __DIR__ . '/../config/db.php';

try {
    $pdo = getDB();

    // Migraciones perezosas
    try { $pdo->query("SELECT productos FROM categorias LIMIT 1"); } catch (Exception $e) {
        $pdo->exec("ALTER TABLE categorias ADD COLUMN productos INT UNSIGNED NOT NULL DEFAULT 0");
    }
    try { $pdo->query("SELECT parent_id FROM categorias LIMIT 1"); } catch (Exception $e) {
        $pdo->exec("ALTER TABLE categorias ADD COLUMN parent_id VARCHAR(50) NULL DEFAULT NULL, ADD INDEX idx_parent_id (parent_id)");
    }

    // Paso 1: conteo directo por categoría (subcategorías y raíces huérfanas con productos)
    $pdo->exec("
        UPDATE categorias c
        SET c.productos = (
            SELECT COUNT(*) FROM productos p
            WHERE p.categoria = c.id AND p.stock_actual > 0
        )
    ");

    // Paso 2: a las raíces les sumamos los productos de todas sus subcategorías
    $pdo->exec("
        UPDATE categorias c
        SET c.productos = c.productos + COALESCE((
            SELECT SUM(sub.productos) FROM (
                SELECT parent_id, SUM(productos) AS productos
                FROM categorias
                WHERE parent_id IS NOT NULL
                GROUP BY parent_id
            ) sub
            WHERE sub.parent_id = c.id
        ), 0)
        WHERE c.parent_id IS NULL
    ");

    $pdo->exec("UPDATE categorias SET activa = IF(productos > 0, 1, 0)");

    echo "ok";
} catch (Exception $e) {
    http_response_code(500);
    echo "error: " . $e->getMessage();
}
