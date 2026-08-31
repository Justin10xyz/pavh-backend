# PAVH

## Visión

PAVH es una aplicación web tipo **dashboard / panel administrativo**, en desarrollo temprano. Está construida como dos repositorios independientes que se comunican vía API REST + cookies de sesión.

## Arquitectura

```
pavh-backend/    Laravel 11 · API REST pura · Sanctum (SPA cookie-based auth) · MySQL
pavh-frontend/   Vue 3 + Vite · Pinia · Vue Router · Tailwind CSS · Axios · PrimeVue 4 (unstyled)
```

- **Auth**: Laravel Sanctum en modo SPA (cookies HttpOnly, no tokens en localStorage). Elegido sobre Passport por menor complejidad y por ser el patrón recomendado para un SPA propio (no API pública de terceros).
- **Entorno**: instalación nativa, sin Docker. `php artisan serve` (backend, :8000) + `npm run dev` (frontend, :5173).
- **Producción (plan)**: backend y frontend bajo el mismo dominio, con nginx como reverse proxy y el backend expuesto bajo `/api`. Esto evita problemas de cookies cross-site que sí aparecen en desarrollo con dominios distintos (por eso no se usa Laravel Valet por ahora — genera dominios `.test` que rompen `SameSite=Lax`).
- **Patrón de capa de datos (backend)**: controladores delgados + Query Scopes nativos de Eloquent + Form Requests + API Resources. Deliberadamente **sin Repository Pattern** — se evaluó y se descartó por ahora: no resuelve ningún problema real con el tamaño actual del catálogo, se reconsiderará si el proyecto crece lo suficiente para justificarlo. Validaciones de negocio que dependen del modelo bindeado por ruta (ej. reglas de stock) viven como guard clause en el controlador, no en el Form Request.
- **Componentes UI (frontend)**: PrimeVue 4 en modo unstyled (MIT para siempre; v5 requiere licencia PrimeUI), estilizado con los tokens del sistema de diseño vía `:deep()` o markup propio en slots.

## Estado actual

### Backend — Auth ✅ completo y verificado
- Sanctum instalado (`php artisan install:api`), `statefulApi()` habilitado
- Endpoints: `login`, `logout`, `user` en `routes/api.php`
- CORS y dominios stateful configurados para `localhost:5173`
- Flujo completo verificado con curl y con `tests/Feature/AuthTest.php`
- Repo Git con commits limpios, ya en remoto

### 1. Inventario — ✅ completo (backend + frontend)
- Catálogo de productos con estructura padre (línea) / variante (color+medida)
- Alertas de stock bajo (umbral por variante, vía `minimum_stock`)
- Alta, edición y borrado (soft delete, con reglas de integridad) de productos y variantes
- Ajuste de stock real vía acción dedicada, con validación de stock insuficiente
- **Unidad de medida por producto**: vive a nivel del producto padre (`unit_type_id`) — pieza/caja vs. m² u otra medida fraccionable — con factor de conversión (`m2_per_box`) a nivel variante
- Pendiente (no bloqueante): revisión de diseño visual de la tabla; importador de listas de precios de proveedores; dashboard de ventas por producto; historial de movimientos de stock

### 2. Cotizaciones y Ventas — ✅ backend completo, 🚧 frontend pendiente
- Generar cotización seleccionando productos del catálogo de Inventario
- Imprimir cotización en tamaño carta/media carta (pendiente, ver módulo 3)
- **Se puede convertir en una Venta (POS) sin recapturar datos, permitiendo ajustar cantidades/precios antes de confirmar** — la cotización es, en esencia, un borrador de venta. Cotización y Venta comparten la misma estructura de líneas de producto/cantidad/precio, y una Venta puede tener un origen: "directa" o "desde cotización".
- `GET /api/quotes` soporta `?with=customer,quoteStatus` (whitelist explícita, valores desconocidos se ignoran silenciosamente) — resuelve N+1 detectado en
QuoteResource (11 queries → 3 queries en listado de 5 registros). Patrón comma+whitelist, extensión del shortcut usado en `ProductController` (que hace match exacto de string) — no unificado entre ambos controllers todavía, queda como decisión pendiente si se quiere consistencia total. 
- `QuotesView.vue` + `src/stores/quotes.js`: listado funcional, verificado en navegador contra backend real (3 registros de prueba cubriendo los tres status y el caso sin cliente). `fetchQuotes()` usa `?with=customer,quoteStatus` para evitar el N+1 (antes lazy-loaded implícitamente vía QuoteResource).

**API REST:**
- Endpoints separados `/api/products` y `/api/product-variants` (más `?with=variants` como atajo de conveniencia), todos bajo `auth:sanctum`
- Endpoints simples de catálogo (`/api/suppliers`, `/api/categories`, `/api/unit-types`, `/api/commission-categories`) para poblar selects del frontend
- Query Scopes (`scopeLowStock`, `scopeByCategory`), Form Requests, API Resources (con `low_stock` calculado)
- CRUD completo de producto y variante, incluyendo `DELETE /product-variants/{id}` (soft delete, rechaza con 422 si es la última variante activa del producto)
- Ajuste de stock como acción dedicada (`PATCH /product-variants/{id}/stock`, `quantity`+`type`), con guard clause que rechaza `subtract` si dejaría el stock en negativo (422 "Stock insuficiente")
- `SoftDeletes` en `Product`/`ProductVariant` — no se borran físicamente porque pueden quedar referenciados en cotizaciones/ventas futuras
- Suite de tests pasando, verificado manualmente con curl y en navegador

### Frontend — Inventario ✅ completo, resto del panel 🚧 en progreso
- Scaffold Vite + Vue 3 + Pinia + Vue Router + Tailwind (vía `@tailwindcss/vite`)
- `src/lib/axios.js`, `src/stores/auth.js`, `src/router/index.js` con guard de sesión
- `src/layouts/AppLayout.vue` y `AuthLayout.vue`, resueltos dinámicamente vía `route.meta.layout`
- `src/views/login/Login.vue` funcional de punta a punta
- `src/components/layout/AppSidebar.vue` y `AppTopbar.vue` implementados (sistema de diseño aplicado, item activo, título dinámico)
- `src/router/` reestructurado por módulo (`auth/`, `dashboard/`, `inventory/`, `quotes/`, `pos/`)
- **`InventoryView.vue`**: catálogo agrupado (PrimeVue `DataTable` con row-expansion), buscador + filtro de categoría + toggle "solo stock bajo" (100% client-side), botón "Editar" a nivel de grupo por tamaño, ícono de ajuste de stock con `Dialog` de PrimeVue
- **`ProductFormView.vue`**: crea/edita producto junto con sus variantes en un solo form — campos técnicos compartidos + lista repetible de colores (cada uno con stock inicial/mínimo); eliminar un color existente dispara `DELETE` inmediato con `ConfirmDialog` de PrimeVue
- **`src/stores/inventory.js`** y **`src/stores/catalogs.js`** (Pinia, patrón `initialized`); mutaciones puntuales (ajuste de stock, borrado de variante) actualizan el store in-place sin refetch completo
- `src/lib/groupVariants.js`: agrupa variantes por medida+PEI+ETT+categoría de comisión+precio, dejando el color como lo único que varía dentro del grupo
- Convención de nombres en inglés aplicada a código (archivos/componentes/rutas internas); contenido de negocio visible y URLs de rutas se quedan en español
- Pendiente: `HomeView.vue` sigue como placeholder del dashboard real

## Decisiones clave y su razón

| Decisión | Razón |
|---|---|
| Sanctum sobre Passport | SPA propio, no necesita OAuth2 completo; cookies HttpOnly evitan XSS de localStorage |
| Sin Docker | Desarrollo nativo, menor fricción para el flujo actual |
| Sin Valet (por ahora) | Dominios `.test` rompen cookies `SameSite=Lax` frente a `localhost:5173` |
| `localhost` en vez de `127.0.0.1` | Son orígenes distintos para el navegador; debe coincidir con `CORS` y `SANCTUM_STATEFUL_DOMAINS` |
| Roles/permisos diferidos | Se definirá cuando el dashboard base esté funcional |
| PrimeVue 4 (unstyled) para tablas/diálogos | MIT permanente; v5 requiere licencia PrimeUI (watermark si no se registra). Estilos vía `:deep()`/markup propio, no vía `pt` (poco confiable entre versiones) |
| Sin Repository Pattern | Controladores delgados + Query Scopes + Form Requests + API Resources cubren las necesidades actuales sin la indirección extra |
| `SoftDeletes` en productos/variantes | Pueden quedar referenciados en cotizaciones/ventas históricas; borrar físicamente rompería esa referencia |
| Código de variante generado server-side | Nunca se acepta desde el cliente, evita colisiones y manipulación; el código del proveedor se guarda solo como referencia libre (`supplier_code`) |
| Validaciones de negocio dependientes del modelo en el controlador, no en el Form Request | Ningún Form Request del proyecto tiene acceso al modelo bindeado por ruta; forzarlo ahí sería inconsistente con el patrón ya establecido |
| `Quote` y `Sale` como entidades separadas (no una tabla con `status`) | Folios independientes por tipo de documento; consistente con "acciones con efecto específico en endpoint dedicado"; reglas de edición distintas (borrador vs. documento ya concretado) |
| `customers` como tabla simple desde ahora, `customer_id` nullable en `quotes`/`sales` | Clientes recurrentes evitan recapturar datos; opcional para permitir venta/cotización rápida sin cliente; módulo completo de clientes queda diferido |
| Cantidad en `quote_items`/`sale_items` vive en m² (no cajas); conversión a cajas (`ceil(quantity / m2_per_box)`) ocurre solo al descontar stock, nunca al calcular precio | Consistente con `unit_price = price_per_m2`; evita que `quantity` signifique unidades distintas entre cotización y venta; redondear hacia arriba refleja que no se puede vender/descontar media caja físicamente, sin alterar el monto cobrado |

## Dominio del negocio

PAVH es para un negocio de **venta de pisos y materiales de construcción**. El cliente actualmente hace notas de venta, cotizaciones y ventas a mano — el objetivo del sistema es modernizar y digitalizar ese flujo completo.

Estructura de catálogo confirmada con datos reales de proveedor (Interceramic): un **producto** es la línea/colección (ej. "Creato"), una **variante** es la combinación color+medida específica (ej. Creato/Taupe/60x120) — es la unidad real que se vende, tiene precio propio y stock. El proveedor vende por caja; el sistema calcula m²/piezas disponibles a partir de un factor de conversión (m² por caja) que el proveedor ya provee en sus listas de precios.

## Módulos

### 1. Inventario — ✅ completo (backend + frontend)
- Catálogo de productos con estructura padre (línea) / variante (color+medida)
- Alertas de stock bajo (umbral por variante, vía `minimum_stock`)
- Alta, edición y borrado (soft delete, con reglas de integridad) de productos y variantes
- Ajuste de stock real vía acción dedicada, con validación de stock insuficiente
- **Unidad de medida por producto**: vive a nivel del producto padre (`unit_type_id`) — pieza/caja vs. m² u otra medida fraccionable — con factor de conversión (`m2_per_box`) a nivel variante
- Pendiente (no bloqueante): revisión de diseño visual de la tabla; importador de listas de precios de proveedores; dashboard de ventas por producto; historial de movimientos de stock

### 2. Cotizaciones y Ventas — ✅ backend completo, 🚧 frontend pendiente
- Generar cotización seleccionando productos del catálogo de Inventario
- Imprimir cotización en tamaño carta/media carta (pendiente, ver módulo 3)
- **Se puede convertir en una Venta (POS) sin recapturar datos, permitiendo ajustar cantidades/precios antes de confirmar** — la cotización es, en esencia, un borrador de venta. Cotización y Venta comparten la misma estructura de líneas de producto/cantidad/precio, y una Venta puede tener un origen: "directa" o "desde cotización".

**Decisión de modelado (resuelta e implementada):** `Quote` y `Sale` son **entidades separadas** (`quotes`/`quote_items` y `sales`/`sale_items`), no una sola tabla con `status`. Razones:
  - Folios independientes por tipo de documento (`COT-0001` vs `V-0001`) — inviable de forma limpia con un solo autoincrement
  - Consistente con la convención ya establecida de "acciones con efecto específico van en endpoint dedicado" (ver ajuste de stock) — convertir cotización en venta es una acción con efectos reales (descuenta stock), no un cambio de status genérico
  - Reglas de edición/borrado distintas por naturaleza: una cotización es un borrador editable libremente mientras esté en status "Borrador"; una venta ya afectó inventario y no tiene PUT/DELETE
  - Se acepta la duplicación estructural entre `quote_items`/`sale_items` (sin tabla polimórfica compartida) — consistente con "sin indirección extra"

**Backend implementado:**
- `customers`: CRUD sin `destroy` (no pedido), con búsqueda simple (`GET /api/customers?search=`) vía `scopeSearch()`, pensado para el buscador/alta inline del form de cotización/venta
- `quotes`/`quote_items`: folio server-side (`QuoteFolioGenerator`, formato `COT-0001`, `withTrashed()`), `unit_price` **siempre** resuelto del `price_per_m2` actual de la variante (ignora cualquier precio que mande el cliente), `subtotal`/`total` calculados server-side (`total = subtotal`, sin impuestos por ahora), edición (`PUT`, reemplaza todas las líneas) permitida **solo** mientras `status = "Borrador"` (guard clause en el controlador, 422 si no)
- `sales`/`sale_items`: folio independiente (`SaleFolioGenerator`, formato `V-0001`). A diferencia de `quotes`, `unit_price` **sí** viene del payload del frontend (el flujo de conversión permite ajustar precio antes de confirmar). Sin `PUT`/`DELETE` (no pedido)
- `GET /api/quotes/{id}/convert`: **solo lectura**, prellenar el form de nueva venta con las líneas de la cotización y el `price_per_m2` **actual** de cada variante (no el precio congelado en la cotización — se le muestra al usuario el precio de hoy). Rechaza 422 si la cotización ya está "Convertida"
- `POST /api/sales`: valida stock suficiente (agregado por variante, cubre el caso de líneas duplicadas de la misma variante) **antes** de mutar nada, descuenta stock dentro de una transacción, y si trae `quote_id` marca esa cotización como "Convertida" automáticamente. Rechaza 422 si el `quote_id` referenciado ya está "Convertida" (evita doble conversión)
- **Conversión de unidades (m² ↔ cajas):** `quote_items.quantity`/`sale_items.quantity` viven en m² (consistente con que `unit_price` = `price_per_m2`), pero `stock_boxes` vive en cajas. Al crear una venta, se convierte con `ceil(quantity / m2_per_box)` por línea, agregando por variante antes de comparar contra stock disponible. Si `m2_per_box` es `null` en la variante, la línea se rechaza con 422 explícito — nunca se asume conversión 1:1. El dinero (`line_total`/`subtotal`/`total`) se calcula siempre sobre la cantidad exacta en m², independiente del redondeo hacia arriba usado para el descuento de stock
- `ProductVariant::hasSufficientStock()` / `decrementStock()`: extraídos como métodos reusables, usados tanto por el endpoint de ajuste de stock existente como por la creación de ventas — evita duplicar el guard de "Stock insuficiente"

**Clientes:** tabla `customers` simple (`name`, `phone`, `email`, sin `SoftDeletes` por ahora), no texto libre. `customer_id` es **nullable** en `quotes` y `sales` (se permite cotización/venta rápida sin capturar cliente). Módulo completo de clientes (edición, historial, etc.) queda diferido — esto es solo el catálogo básico + búsqueda.

### 3. Punto de Venta (POS)
- Registrar ventas, ya sea directas o convertidas desde una cotización existente
- Descontar stock de Inventario automáticamente al concretar la venta
- Imprimir nota de venta en **tamaño carta/media carta** (no ticket térmico — esto descarta impresoras térmicas de 58mm/80mm como requisito, se resuelve con impresión estándar/PDF)

### Implicaciones técnicas a resolver cuando se construya cada módulo
- ~~`Cotizacion` y `Venta` comparten estructura de líneas — evaluar si `Venta` es una entidad separada...~~ ✅ resuelto — ver spec de datos en la sección de Cotizaciones arriba. Las líneas (`quote_items`/`sale_items`) referencian `product_variants` directamente
- Impresión: generar PDF carta/media carta (Laravel + librería PDF, ej. dompdf) — pendiente de decidir en detalle cuando se llegue a este módulo
- El diseño de `SoftDeletes` en variantes ya contempla que queden referenciadas desde cotizaciones/ventas sin romperse

## Roadmap

1. ~~Sistema de diseño~~ ✅ — definido en `AGENT.md` (paleta, tipografía, layout)
2. Dashboard real (reemplazar `HomeView.vue` placeholder) — pendiente
3. ~~Limpieza de scaffold sin usar~~ ✅
4. ~~Navegación principal (sidebar + topbar)~~ ✅
5. ~~Módulo de Inventario~~ ✅ — backend y frontend completos (catálogo, CRUD de producto y variante, ajuste de stock)
6. **Módulo de Cotizaciones y Ventas** (depende del catálogo de Inventario, ya listo) — backend ✅ completo (`customers`, `quotes`/`quote_items`, `sales`/`sale_items`, conversión, descuento de stock); frontend 🚧 siguiente paso
7. Módulo de Punto de Venta (impresión de nota de venta; el backend de creación de venta y descuento de stock ya vive en el paso 6, POS es principalmente la UI de venta directa/rápida + impresión)
8. Roles y permisos
9. ~~Selección de librería de componentes~~ ✅ — PrimeVue 4 (unstyled)

## Convenciones de commits

Conventional Commits, descripciones en inglés, con gitmoji:

```
<type>[optional scope]: <gitmoji> <description>
```

Ejemplos:
```
feat(auth): :sparkles: add password visibility toggle to login form
fix(router): :bug: prevent duplicate fetchUser call on navigation
config(cors): :wrench: allow credentials from localhost:5173
hotfix(auth): :ambulance: fix logout using wrong guard
feat(inventory): :sparkles: add product variant stock adjustment endpoint
fix(inventory): :bug: refresh model after insert to reflect MySQL column defaults
fix(inventory): :bug: add missing stock_boxes validation rule to form requests
feat(inventory): :sparkles: add variant delete with confirm dialog
```
