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

### Backend — Inventario ✅ completo y verificado
**Capa de datos:**
- Tablas (nombres en inglés): `categories`, `unit_types`, `suppliers`, `commission_categories`, `products` (padre/línea), `product_variants` (color+medida, unidad real con stock)
- Modelos con relaciones `belongsTo`/`hasMany`
- Servicio `app/Services/VariantCodeGenerator`: genera `code` único por variante con formato `[PREFIJO]-[LINEA]-[COLOR]-[MEDIDA]` (ej. `PIS-CREATO-TAU-60X120`), con cascada de resolución de colisión
- Sembrado con datos reales de proveedor (Interceramic)

**API REST:**
- Endpoints separados `/api/products` y `/api/product-variants` (más `?with=variants` como atajo de conveniencia), todos bajo `auth:sanctum`
- Endpoints simples de catálogo (`/api/suppliers`, `/api/categories`, `/api/unit-types`, `/api/commission-categories`) para poblar selects del frontend
- Query Scopes (`scopeLowStock`, `scopeByCategory`), Form Requests, API Resources (con `low_stock` calculado)
- CRUD completo de producto y variante, incluyendo `DELETE /product-variants/{id}` (soft delete, rechaza con 422 si es la última variante activa del producto)
- Ajuste de stock como acción dedicada (`PATCH /product-variants/{id}/stock`, `quantity`+`type`), con guard clause que rechaza `subtract` si dejaría el stock en negativo (422 "Stock insuficiente")
- `SoftDeletes` en `Product`/`ProductVariant` — no se borran físicamente porque pueden quedar referenciados en cotizaciones/ventas futuras
- `ProductVariantResource` expone `product` (`id`+`name` de la línea) vía `whenLoaded` — agregado para que el detalle de cotización pueda mostrar línea/color/medida sin depender de que Inventario esté cargado en el frontend
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
| `ProductVariantResource.product` vía `whenLoaded` en vez de resolverlo client-side cruzando datos de Inventario | El detalle de cotización no puede depender de que `inventory.products` ya esté cargado (se puede abrir el link directo); mismo patrón que `commissionCategory` |

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

### 2. Cotizaciones — ✅ completo (backend + frontend)
- Generar cotización seleccionando productos del catálogo de Inventario
- Imprimir cotización en tamaño carta/media carta (pendiente, ver módulo 3)
- **Se puede convertir en una Venta (POS) sin recapturar datos, permitiendo ajustar cantidades/precios antes de confirmar** — la cotización es, en esencia, un borrador de venta. Cotización y Venta comparten la misma estructura de líneas de producto/cantidad/precio, y una Venta puede tener un origen: "directa" o "desde cotización".
- `GET /api/quotes` soporta `?with=customer,quoteStatus` (whitelist explícita, valores desconocidos se ignoran silenciosamente) — resuelve N+1 detectado en
QuoteResource (11 queries → 3 queries en listado de 5 registros). Patrón comma+whitelist, extensión del shortcut usado en `ProductController` (que hace match exacto de string) — no unificado entre ambos controllers todavía, queda como decisión pendiente si se quiere consistencia total.

### Frontend — Cotizaciones ✅ completo
- **Listado** (`QuotesView.vue`) ✅ — `DataTable`, búsqueda client-side por folio/cliente, badge de status con color (`statusClasses()` ahora vive en `src/lib/quoteStatus.js`, compartido con el detalle).
- **Creación** (`QuoteFormView.vue`) ✅ — cliente opcional, notas, líneas de producto con autocomplete de variante (búsqueda client-side sobre `inventory.fetchProducts()` — no existe endpoint de búsqueda de variantes por texto libre en el backend), fusión automática de variante duplicada, cantidad en m² con equivalente en cajas informativo, precio siempre server-resolved, aviso no bloqueante de stock insuficiente, totales en vivo.
- **Detalle** (`QuoteDetailView.vue`) ✅ — reemplazó el placeholder. Header con folio/status/fecha, datos generales (cliente o "Sin cliente", notas), tabla de líneas (producto/color/medida vía `product_variant.product`, cantidad en m², cajas equivalentes, precio y subtotal **congelados** — no el precio de hoy), totales. Botón "Editar" habilitado solo si `status === 'Borrador'`; botón "Convertir a venta" deshabilitado hasta que exista el módulo de POS (ver módulo 3).
- **Edición** (`QuoteFormView.vue`, mismo componente que creación) ✅ — detecta modo edición vía `route.params.id`; si la cotización cargada no está en Borrador, redirige al detalle (el guard real vive en el backend, esto solo evita mostrar un form que el backend rechazaría); puebla `lines` directamente desde `currentQuote.items` (ya trae `product_variant.product` anidado, no depende de que Inventario esté cargado); al guardar llama `PUT` y redirige al detalle en vez de al listado.
- `stores/quotes.js`: `fetchQuote(id)` y `updateQuote(id, payload)` agregados junto a `fetchQuotes()`/`createQuote()`, mismo patrón `initialized` + mutación in-place.
- Pendiente (no bloqueante): cancelación de cotización — el status "Cancelada" ya existe en `quote_statuses` y el listado ya lo pinta, pero no hay endpoint ni UI que la dispare todavía.
- Hay una guía completa del módulo (modelo de datos, flujo end-to-end, endpoints, decisiones) en el doc de Claude "Guía del módulo — Cotizaciones".

### Pendiente transversal
- Pase de UX/UI y estilos, módulo por módulo, una vez cerrada la cobertura
  funcional completa de todos los módulos (decisión de Justin — evitar
  pulir vistas que aún pueden cambiar de forma).

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

### 3. Punto de Venta (POS) — 🚧 en progreso

- Backend de `sales`/`sale_items` completo (ver sección de Cotizaciones) — ya incluye `GET /api/sales`, `GET /api/sales/{id}`, `POST /api/sales`
- **Venta directa** (`SaleFormView.vue`) ✅ — cliente opcional (`CustomerSearch.vue`, extraído de Cotizaciones), líneas con `VariantAutocomplete.vue` (también extraído), precio editable (a diferencia de Cotizaciones), fusión de variante duplicada, aviso no bloqueante de stock insuficiente (relevante por el sobrepedido, común en este negocio), sincronización in-place del stock de Inventario tras la venta. Al confirmar, resetea el form en lugar de navegar — pensado para flujo de mostrador ("siguiente cliente")
- **Convertir cotización a venta** ✅ — mismo `SaleFormView.vue`, detecta modo vía `?quote_id=` en la URL, prellena desde `GET /quotes/{id}/convert`, bloquea con mensaje claro si alguna variante de la cotización ya no está disponible (soft-deleted, validado también en backend) o si el `quote_id` no está disponible por caché desactualizada (reintenta un refetch antes de bloquear). Al confirmar, redirige al detalle de la cotización en vez de resetear
- Botón "Convertir a venta" en `QuoteDetailView.vue` habilitado, visible/activo solo si `status === 'Borrador'`
- **Backend — rechazo centralizado de conversión inválida**: `Quote::conversionBlockedMessage()` en el modelo, usado por `QuoteController@convert` y `SaleController@store`, rechaza cualquier estado que no sea "Borrador" (no solo "Convertida") — cubre "Cancelada" y cualquier estado futuro sin tener que tocar los controladores de nuevo
- **Nueva convención de frontend**: componentes reutilizables entre módulos viven en `src/components/widgets/<tipo>/` (ej. `autocompletes/`) — ver `AGENT.md`
- `PosView.vue` es un hub mínimo con un solo botón "Nueva venta" — se ampliará cuando exista el listado
- Pendiente: listado de ventas (`SalesView.vue`), impresión de nota de venta (decisión pendiente: PDF server-side vs. `window.print()` frontend)
- Bug conocido no bloqueante (Cotizaciones, detectado durante este trabajo): el `<form v-else>` en `QuoteFormView.vue` depende de `generalError`, oculta todo el form en cualquier error de submit hasta recargar
- Bug conocido no bloqueante: el listado cacheado de cotizaciones (`quotes.quotes` en el store) no refleja el nuevo estado "Convertida" tras convertir desde el detalle, hasta recargar el listado — el detalle sí refresca correctamente

### Pendiente antes de desplegar a producción
- **Migración de zona horaria**: `config/app.php` cambió de `UTC` a `America/Mexico_City` (necesario para que los filtros de fecha de ventas y el PDF usen el día local correctamente). Cualquier dato cargado en producción *antes* de este cambio tiene `created_at`/`updated_at`/`deleted_at` en UTC sin marcar como tal — al desplegar, se necesita una migración que reste 6 horas a esas columnas en las tablas afectadas, o los registros viejos se van a ver desfasados.

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
6. ~~Módulo de Cotizaciones~~ ✅ — backend y frontend completos (listar, crear, ver detalle, editar). Pendiente no bloqueante: cancelación
7. **Módulo de Punto de Venta** (el backend de creación de venta y descuento de stock ya vive en el paso 6) — siguiente paso: listado/creación de venta directa, UI de "Convertir a venta" (conecta el botón ya existente en el detalle de cotización), impresión de nota de venta
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
