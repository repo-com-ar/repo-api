<?php
/**
 * Cron — Recalcula la cantidad de productos con stock por categoría.
 *
 * Actualiza categorias.productos con el COUNT de productos cuyo stock_actual > 0
 * y que pertenecen a esa categoría.
 *
 * Expuesto en: https://api.repo.com.ar/cron/categorias_productos
 */
header('Content-Type: text/plain');

require_once __DIR__ . '/../config/db.php';

try {
    $pdo = getDB();

    // Migración perezosa: agregar columna si aún no existe
    try { $pdo->query("SELECT productos FROM categorias LIMIT 1"); } catch (Exception $e) {
        $pdo->exec("ALTER TABLE categorias ADD COLUMN productos INT UNSIGNED NOT NULL DEFAULT 0");
    }

    $pdo->exec("
        UPDATE categorias c
        SET c.productos = (
            SELECT COUNT(*) FROM productos p
            WHERE p.categoria = c.id AND p.stock_actual > 0
        )
    ");

    echo "ok";
} catch (Exception $e) {
    http_response_code(500);
    echo "error: " . $e->getMessage();
}
