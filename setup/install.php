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
            created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
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
    $pdo->exec("DROP TRIGGER IF EXISTS tr_productos_sku");
    msg("Trigger <b>tr_productos_sku</b> eliminado (SKU ahora se genera en PHP)", 'info');
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
            total       DECIMAL(12,2) NOT NULL DEFAULT 0,
            estado      VARCHAR(30)  NOT NULL DEFAULT 'recibido',
            lat         DECIMAL(10,7) DEFAULT NULL,
            lng         DECIMAL(10,7) DEFAULT NULL,
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
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            cliente_id  INT UNSIGNED NOT NULL DEFAULT 0,
            detalle     VARCHAR(500) NOT NULL,
            created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    msg("Tabla <b>eventos</b> creada/verificada", 'ok');
} catch (Exception $e) {
    msg("Error creando tabla eventos: " . htmlspecialchars($e->getMessage()), 'error');
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
        'precio_km'              => '0',
        'datarocket_url'         => 'https://api.databox.net.ar',
        'datarocket_apikey'      => 'z9SACoW1SiHGiyan6JVMwudC73r7Y0An',
        'datarocket_proyecto'    => 'vigicom',
        'datarocket_canal_email' => 'databox',
        'datarocket_canal_wa'    => 'repo-hum',
        'datarocket_remitente'   => 'Repo Online',
        'datarocket_remite'      => '1169391123',
    ];
    $stmtCfg = $pdo->prepare("INSERT IGNORE INTO configuracion (clave, valor) VALUES (?, ?)");
    foreach ($cfgDefaults as $clave => $valor) {
        $stmtCfg->execute([$clave, $valor]);
    }
    msg("Configuración por defecto insertada (<b>" . count($cfgDefaults) . "</b> claves)", 'ok');
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
    // lat, lng en pedidos
    try {
        $pdo->query("SELECT lat FROM pedidos LIMIT 1");
        msg("Columnas <b>lat, lng</b> ya existen en pedidos", 'info');
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE pedidos ADD COLUMN lat DECIMAL(10,7) DEFAULT NULL, ADD COLUMN lng DECIMAL(10,7) DEFAULT NULL");
        msg("Columnas <b>lat, lng</b> agregadas a pedidos", 'ok');
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

// ── 2. Insertar categorías de ejemplo ────────────────────────────
if ($ok) {
    $countCat = $pdo->query("SELECT COUNT(*) FROM categorias")->fetchColumn();
    if ($countCat > 0) {
        msg("La tabla categorias ya tiene <b>{$countCat}</b> registros. No se insertarán duplicados.", 'warn');
    } else {
        function px($id) {
            return "https://images.pexels.com/photos/{$id}/pexels-photo-{$id}.jpeg?auto=compress&cs=tinysrgb&w=80&h=80&fit=crop";
        }

        $cats = [
            ['frutas',    'Frutas',    '🍎', px(102104),   1],
            ['verduras',  'Verduras',  '🥕', px(73640),    2],
            ['lacteos',   'Lácteos',   '🥛', px(12984540), 3],
            ['carnes',    'Carnes',    '🥩', px(112781),   4],
            ['fiambres',  'Fiambres',  '🥓', px(4871119),  5],
            ['panaderia', 'Panadería', '🍞', px(41298),    6],
            ['bebidas',   'Bebidas',   '🥤', px(31012801), 7],
            ['almacen',   'Almacén',   '🏪', px(8108170),  8],
        ];

        $stmtCat = $pdo->prepare("INSERT INTO categorias (id, label, emoji, imagen, orden) VALUES (?, ?, ?, ?, ?)");
        foreach ($cats as $c) { $stmtCat->execute($c); }
        msg("Se insertaron <b>" . count($cats) . "</b> categorías", 'ok');
    }
}

// ── 3. Insertar productos de ejemplo ─────────────────────────────
if ($ok) {
    $count = $pdo->query("SELECT COUNT(*) FROM productos")->fetchColumn();
    if ($count > 0) {
        msg("La tabla productos ya tiene <b>{$count}</b> registros. No se insertarán duplicados.", 'warn');
    } else {
        function img($id) {
            return "https://images.pexels.com/photos/{$id}/pexels-photo-{$id}.jpeg?auto=compress&cs=tinysrgb&w=400&h=400&fit=crop";
        }
        function uns($id) {
            return "https://images.unsplash.com/photo-{$id}?w=400&h=400&fit=crop&auto=format&q=80";
        }

        $productos = [
            ['Manzana Roja',      850,  'frutas',    img(102104),              'kg', 1],
            ['Banana',            620,  'frutas',    uns('A4IIDSz6bTM'),       'kg', 1],
            ['Naranja Navel',     750,  'frutas',    uns('ZBXPPacUsVs'),       'kg', 1],
            ['Limón',             480,  'frutas',    uns('enNffryKuQI'),       'kg', 1],
            ['Pera Williams',     920,  'frutas',    uns('p9tDuQJV244'),       'kg', 0],
            ['Frutilla',          1200, 'frutas',    uns('THRRhA1ZGMk'),       'kg', 1],
            ['Tomate Perita',     690,  'verduras',  uns('aQbPCDVSX58'),       'kg', 1],
            ['Lechuga Crespa',    450,  'verduras',  uns('5MU_4hPl67Y'),       'u',  1],
            ['Zanahoria',         380,  'verduras',  uns('fWGBs1ol4_w'),       'kg', 1],
            ['Papa Blanca',       420,  'verduras',  uns('JqYqM-udWH4'),       'kg', 1],
            ['Cebolla',           350,  'verduras',  uns('iUGPq02__Gc'),       'kg', 1],
            ['Ajo',               2800, 'verduras',  uns('bC1fXU1v98U'),       'kg', 1],
            ['Leche Entera 1L',   1100, 'lacteos',   uns('c6TKtsi8C1k'),       'u',  1],
            ['Queso Cremoso',     3500, 'lacteos',   img(7525004),             'kg', 1],
            ['Yogur Natural',     980,  'lacteos',   img(4428345),             'u',  1],
            ['Manteca 200g',      1450, 'lacteos',   img(3821250),             'u',  1],
            ['Crema de Leche',    870,  'lacteos',   img(5336006),             'u',  0],
            ['Milanesa Ternera',  6200, 'carnes',    uns('iehau6a1l8Q'),       'kg', 1],
            ['Pollo Entero',      4800, 'carnes',    uns('HQ22vVXhWcc'),       'kg', 1],
            ['Asado',             7500, 'carnes',    uns('YlAmh_X_SsE'),       'kg', 1],
            ['Chorizo',           5200, 'carnes',    uns('RAoX-N4ZcK4'),       'kg', 1],
            ['Pan Lactal',        1350, 'panaderia', uns('h3MVMRHitDU'),       'u',  1],
            ['Medialunas x6',     1800, 'panaderia', uns('vU59Ut9vpQA'),       'u',  1],
            ['Galletas Salvado',  890,  'panaderia', img(479628),              'u',  1],
            ['Agua Mineral 1.5L', 650,  'bebidas',   img(31012801),            'u',  1],
            ['Gaseosa 1.5L',      1200, 'bebidas',   uns('wKyxuVQDyP0'),       'u',  1],
            ['Jugo Naranja 1L',   1480, 'bebidas',   img(26791666),            'u',  1],
            ['Cerveza Lata',      980,  'bebidas',   uns('p5_XIonZdLc'),       'u',  1],
            ['Arroz Largo 1kg',   1650, 'almacen',   uns('q-meIszitTs'),       'u',  1],
            ['Fideos Spaghetti',  980,  'almacen',   img(4056907),             'u',  1],
            ['Aceite Girasol 1L', 2100, 'almacen',   uns('KcdN8uj47EU'),       'u',  1],
            ['Sal Fina 1kg',      480,  'almacen',   img(6401),               'u',  1],
            ['Azúcar 1kg',        1100, 'almacen',   img(13466248),            'u',  1],
            ['Café Molido 250g',  2800, 'almacen',   uns('3uXUiDjgH6U'),       'u',  1],
        ];

        $stmt = $pdo->prepare("
            INSERT INTO productos (nombre, precio, categoria, imagen, unidad, stock_actual)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $insertados = 0;
        foreach ($productos as $p) {
            $stmt->execute($p);
            $insertados++;
        }
        msg("Se insertaron <b>{$insertados}</b> productos de ejemplo", 'ok');
    }
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
