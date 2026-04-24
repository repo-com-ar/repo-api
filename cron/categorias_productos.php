<?php
/**
 * Cron — Recalcula productos con stock y el flag activa por categoría.
 *
 * Modelo: 3 niveles. Los productos se asignan siempre al 3er nivel.
 *   Primer nivel (raíz)         = parent_id IS NULL
 *   Segundo nivel (sub)         = parent_id apunta a una raíz
 *   Tercer nivel (subsub)       = parent_id apunta a una sub
 *
 * Cálculo:
 *   1. Tercer nivel  → cuenta sus productos con stock directamente.
 *   2. Segundo nivel → suma las subsubcategorías (3er nivel) que tiene adentro.
 *   3. Raíz          → suma las subcategorías (2do nivel) que tiene adentro.
 *
 * Todo producto que accidentalmente haya quedado asignado a nivel 1 o 2 no se
 * contabiliza: el rollup es puramente jerárquico sobre hojas de 3er nivel.
 * Al final: activa = 1 si productos > 0, 0 si no.
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

    // Reiniciamos en cero para evitar arrastrar conteos previos en ningún nivel.
    $pdo->exec("UPDATE categorias SET productos = 0");

    // Paso 1 — 3er nivel: conteo directo de productos con stock.
    // Una categoría es de 3er nivel si su padre tiene a la vez un padre (el raíz).
    $pdo->exec("
        UPDATE categorias c
        JOIN categorias p ON p.id = c.parent_id AND p.parent_id IS NOT NULL
        SET c.productos = (
            SELECT COUNT(*) FROM productos pr
            WHERE pr.categoria = c.id AND pr.stock_actual > 0
        )
    ");

    // Paso 2 — 2do nivel: suma de sus hijas de 3er nivel.
    // Identificamos 2do nivel como 'tiene padre y ese padre es raíz'.
    $pdo->exec("
        UPDATE categorias c
        JOIN categorias p ON p.id = c.parent_id AND p.parent_id IS NULL
        LEFT JOIN (
            SELECT parent_id, SUM(productos) AS s
            FROM categorias
            WHERE parent_id IS NOT NULL
            GROUP BY parent_id
        ) agg ON agg.parent_id = c.id
        SET c.productos = COALESCE(agg.s, 0)
    ");

    // Paso 3 — raíz: suma de sus hijas de 2do nivel (ya enriquecidas con 3er nivel).
    $pdo->exec("
        UPDATE categorias c
        LEFT JOIN (
            SELECT parent_id, SUM(productos) AS s
            FROM categorias
            WHERE parent_id IS NOT NULL
            GROUP BY parent_id
        ) agg ON agg.parent_id = c.id
        SET c.productos = COALESCE(agg.s, 0)
        WHERE c.parent_id IS NULL
    ");

    $pdo->exec("UPDATE categorias SET activa = IF(productos > 0, 1, 0)");

    echo "ok";
} catch (Exception $e) {
    http_response_code(500);
    echo "error: " . $e->getMessage();
}
