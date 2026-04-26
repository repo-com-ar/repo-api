# repo-api — Configuración compartida, base de datos y cron jobs

Repositorio central que cumple tres funciones:

1. **Conexión compartida a la base de datos** (`config/db.php`) — los otros repos la incluyen.
2. **Instalador del esquema** (`setup/install.php`) — crea todas las tablas.
3. **Cron jobs** (`cron/`) — scripts HTTP que corren periódicamente desde el crontab del servidor.

El dominio `api.repo.com.ar` apunta al directorio raíz de este repo y expone `cron/*.php` como endpoints públicos. No hay endpoints de negocio propios; esos viven en `repo-app/api`, `repo-admin/api`, etc.

---

## Estructura

```
repo-api/
├── config/
│   └── db.php                  # Conexión PDO compartida — getDB()
├── setup/
│   └── install.php             # Instalador del esquema (ejecutar una sola vez)
├── cron/                       # Scripts HTTP para cron jobs
│   └── categorias_productos.php
└── linux/
    ├── crontab                 # Líneas del crontab del servidor
    ├── vhost.conf              # VirtualHosts de Apache para todos los dominios
    └── renovar.sh              # Script de renovación SSL
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
| `categorias` | Categorías de productos con soporte jerárquico (hasta 3 niveles, campo `parent_id`) |
| `productos` | Inventario con stock, precios e imágenes |
| `clientes` | Cuentas de clientes (repo-app) |
| `pedidos` | Órdenes con estado, distancia, tiempo estimado, método/estado de pago y fecha de entrega |
| `pedido_items` | Líneas de cada pedido |
| `preparaciones` | Órdenes de preparación vinculadas a pedidos |
| `preparaciones_items` | Líneas de cada preparación |
| `carritos` | Carritos de compra activos |
| `carritos_items` | Líneas de cada carrito |
| `compras` | Órdenes de compra a proveedores |
| `compra_items` | Líneas de cada compra |
| `proveedores` | Proveedores |
| `pagos` | Registro de pagos por pedido (efectivo o Mercado Pago) |
| `repartidores` | Cuentas de repartidores (repo-delivery) |
| `push_subscriptions` | Suscripciones Web Push de clientes, repartidores y administradores |
| `notificaciones` | Historial de notificaciones push enviadas |
| `cuentas` | Plan de cuentas contable (jerárquico) |
| `asientos` | Asientos contables |
| `asientos_detalle` | Líneas de débito/crédito de cada asiento |
| `usuarios` | Cuentas de administradores (repo-admin) |
| `mensajes` | Historial de mensajes WhatsApp/email enviados |
| `eventos` | Log de actividad del sistema |
| `configuracion` | Parámetros del sistema (clave → valor) |

---

## Cron jobs (`cron/`)

Los cron jobs son scripts PHP expuestos como endpoints HTTP bajo `https://api.repo.com.ar/cron/`. El crontab del servidor los invoca con `curl`, en vez de ejecutar PHP por CLI. Esto permite que compartan el mismo intérprete PHP y las mismas dependencias que el resto del sitio, y que se puedan probar manualmente abriendo la URL en el navegador.

Cada script:

- Incluye `config/db.php` para obtener la conexión PDO.
- Hace una sola tarea puntual (recalcular agregados, limpiar datos vencidos, enviar recordatorios, etc.).
- Responde `ok` en éxito, o `error: <mensaje>` con HTTP 500 en falla — conveniente para los logs de cron.
- Puede aplicar migraciones perezosas (`ALTER TABLE` dentro de try/catch) si el schema cambió.

### Cron jobs actuales

| Endpoint | Frecuencia | Descripción |
|---|---|---|
| `/cron/categorias_productos` | cada 5 min | Recalcula `categorias.productos` = conteo de productos activos con `stock_actual > 0`, propagando el conteo hacia los niveles padre en la jerarquía de hasta 3 niveles |

### Agregar un nuevo cron job

1. Crear `cron/<nombre>.php` siguiendo el patrón de los existentes.
2. Agregar la línea en `linux/crontab`:

```cron
*/30 * * * * curl https://api.repo.com.ar/cron/<nombre>
```

3. Instalar el crontab en el servidor: `crontab linux/crontab`.

---

## Repos que dependen de este

| Repo | Descripción |
|---|---|
| `repo-app` | Tienda online para clientes |
| `repo-admin` | Panel de administración |
| `repo-delivery` | App para repartidores |
