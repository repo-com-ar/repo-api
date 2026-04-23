# repo-api — Configuración compartida y base de datos

Repositorio central que contiene la conexión a la base de datos, el instalador del esquema y los scripts de procesos automáticos. No expone endpoints propios; los otros tres repos dependen de él.

---

## Estructura

```
repo-api/
├── config/
│   └── db.php          # Conexión PDO compartida — getDB()
├── setup/
│   └── install.php     # Instalador del esquema (ejecutar una sola vez)
└── robot/              # Scripts de procesos automáticos (cron jobs)
```

---

## Conexión a la base de datos

Todos los repos obtienen la conexión incluyendo `config/db.php`:

```php
require_once __DIR__ . '/../../repo-api/config/db.php';
$pdo = getDB();
```

`getDB()` devuelve una instancia PDO configurada con charset `utf8mb4`, zona horaria Argentina (UTC-3) y `FETCH_ASSOC` como modo de fetch por defecto.

---

## Instalación del esquema

Ejecutar una sola vez. Crea todas las tablas y aplica las migraciones:

```bash
php setup/install.php
```

### Tablas

| Tabla | Descripción |
|---|---|
| `categorias` | Categorías de productos |
| `productos` | Inventario con stock, precios e imágenes |
| `clientes` | Cuentas de clientes (repo-app) |
| `pedidos` | Órdenes con estado, distancia y tiempo estimado |
| `pedido_items` | Líneas de cada pedido |
| `carritos` | Carritos de compra activos |
| `carritos_items` | Líneas de cada carrito |
| `compras` | Órdenes de compra a proveedores |
| `compra_items` | Líneas de cada compra |
| `proveedores` | Proveedores |
| `repartidores` | Cuentas de repartidores (repo-delivery) |
| `usuarios` | Cuentas de administradores (repo-admin) |
| `mensajes` | Historial de mensajes WhatsApp/email enviados |
| `eventos` | Log de actividad del sistema |
| `otp_codigos` | Códigos OTP temporales para login sin contraseña |
| `configuracion` | Parámetros del sistema (clave → valor) |

---

## Scripts automáticos (`robot/`)

Scripts PHP ejecutados por cron. Cada script verifica que se ejecuta desde CLI:

```php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }
```

### Agregar un nuevo cron job

1. Crear el script en `robot/nombre_tarea.php`.
2. Agregar la línea en el crontab del servidor:

```bash
# Ejemplo: ejecutar cada 30 minutos
*/30 * * * * php /var/www/repo-api/robot/nombre_tarea.php
```

---

## Repos que dependen de este

| Repo | Descripción |
|---|---|
| `repo-app` | Tienda online para clientes |
| `repo-admin` | Panel de administración |
| `repo-delivery` | App para repartidores |
