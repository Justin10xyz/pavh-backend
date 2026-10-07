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
- **Zona horaria de la aplicación**: `America/Mexico_City` (`config/app.php`) — ver nota de migración pendiente para datos previos al cambio, en "Pendiente antes de desplegar a producción".

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
- Pendiente: `HomeView.vue` sigue como placeholder del dashboard real (ver módulo 4, Dashboard, ya en progreso)

## Decisiones clave y su razón

| Decisión                                                                                                                                                                                                               | Razón                                                                                                                                                                                                                                                         |
| ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Sanctum sobre Passport                                                                                                                                                                                                 | SPA propio, no necesita OAuth2 completo; cookies HttpOnly evitan XSS de localStorage                                                                                                                                                                          |
| Sin Docker                                                                                                                                                                                                             | Desarrollo nativo, menor fricción para el flujo actual                                                                                                                                                                                                        |
| Sin Valet (por ahora)                                                                                                                                                                                                  | Dominios `.test` rompen cookies `SameSite=Lax` frente a `localhost:5173`                                                                                                                                                                                      |
| `localhost` en vez de `127.0.0.1`                                                                                                                                                                                      | Son orígenes distintos para el navegador; debe coincidir con `CORS` y `SANCTUM_STATEFUL_DOMAINS`                                                                                                                                                              |
| Roles/permisos diferidos                                                                                                                                                                                               | Se definirá cuando el dashboard base esté funcional                                                                                                                                                                                                           |
| PrimeVue 4 (unstyled) para tablas/diálogos                                                                                                                                                                             | MIT permanente; v5 requiere licencia PrimeUI (watermark si no se registra). Estilos vía `:deep()`/markup propio, no vía `pt` (poco confiable entre versiones)                                                                                                 |
| Sin Repository Pattern                                                                                                                                                                                                 | Controladores delgados + Query Scopes + Form Requests + API Resources cubren las necesidades actuales sin la indirección extra                                                                                                                                |
| `SoftDeletes` en productos/variantes                                                                                                                                                                                   | Pueden quedar referenciados en cotizaciones/ventas históricas; borrar físicamente rompería esa referencia                                                                                                                                                     |
| Código de variante generado server-side                                                                                                                                                                                | Nunca se acepta desde el cliente, evita colisiones y manipulación; el código del proveedor se guarda solo como referencia libre (`supplier_code`)                                                                                                             |
| Validaciones de negocio dependientes del modelo en el controlador, no en el Form Request                                                                                                                               | Ningún Form Request del proyecto tiene acceso al modelo bindeado por ruta; forzarlo ahí sería inconsistente con el patrón ya establecido                                                                                                                      |
| `Quote` y `Sale` como entidades separadas (no una tabla con `status`)                                                                                                                                                  | Folios independientes por tipo de documento; consistente con "acciones con efecto específico en endpoint dedicado"; reglas de edición distintas (borrador vs. documento ya concretado)                                                                        |
| `customers` como tabla simple desde ahora, `customer_id` nullable en `quotes`/`sales`                                                                                                                                  | Clientes recurrentes evitan recapturar datos; opcional para permitir venta/cotización rápida sin cliente; módulo completo de clientes queda diferido                                                                                                          |
| Cantidad en `quote_items`/`sale_items` vive en m² (no cajas); conversión a cajas (`ceil(quantity / m2_per_box)`) ocurre solo al descontar stock, nunca al calcular precio                                              | Consistente con `unit_price = price_per_m2`; evita que `quantity` signifique unidades distintas entre cotización y venta; redondear hacia arriba refleja que no se puede vender/descontar media caja físicamente, sin alterar el monto cobrado                |
| `ProductVariantResource.product` vía `whenLoaded` en vez de resolverlo client-side cruzando datos de Inventario                                                                                                        | El detalle de cotización no puede depender de que `inventory.products` ya esté cargado (se puede abrir el link directo); mismo patrón que `commissionCategory`                                                                                                |
| Rechazo de conversión centralizado en `Quote::conversionBlockedMessage()`, rechaza cualquier estado que no sea "Borrador"                                                                                              | Evita que agregar un estado futuro a `quote_statuses` obligue a tocar de nuevo `QuoteController@convert` y `SaleController@store`; antes solo se rechazaba "Convertida", dejando pasar "Cancelada" sin querer                                                 |
| `DocumentPdfGenerator` recibe una estructura de datos neutral (no un modelo Eloquent)                                                                                                                                  | Pensado para reusarse entre `Sale` y `Quote` sin acoplarse a ninguno de los dos; cada controlador arma su propia estructura y se la pasa al servicio                                                                                                          |
| Zona horaria de la app cambiada de `UTC` a `America/Mexico_City`                                                                                                                                                       | Los filtros de fecha de ventas (`from`/`to`) y la fecha mostrada en el PDF deben usar el día local del negocio, no UTC — una venta de las 20:00 no debe aparecer con fecha del día siguiente                                                                  |
| Lógica de descarga/compartir PDF en un composable (`useDocumentPdf.js`), no duplicada por vista                                                                                                                        | Segundo consumidor real (Cotizaciones) del mismo patrón ya usado en Venta — cumple el criterio ya establecido de extraer a reusable solo cuando hay un segundo caso de uso real, no antes                                                                     |
| `dashboard.js` no usa el patrón `initialized`, hace sus propios `GET /sales`/`/quotes` en paralelo en vez de reusar `sales.fetchSales()`/`quotes.fetchQuotes()`                                                        | Datos del dashboard deben refrescarse en cada visita, no cachearse; reusar esas acciones habría escrito en los stores de Ventas/Cotizaciones (listados de otras vistas) y heredado su gate de caché/contador de petición, pensados para su propio caso de uso |
| Widgets de `HomeView.vue` (`SalesSummaryCards`, `DashboardShortcuts`, `LowStockWidget`, `ActiveQuotesWidget`) en `src/views/dashboard/components/`, no en `src/components/widgets/`                                    | Solo el Dashboard los usa hoy; `AGENT.md` reserva `widgets/` para componentes reusados entre módulos — se mueven ahí si surge un segundo consumidor real                                                                                                      |
| `dashboard.js` usa `Promise.allSettled()` con loading/error separado por widget (`salesLoading`/`salesError`, `lowStockLoading`/`lowStockError`, `activeQuotesLoading`/`activeQuotesError`), no un loading/error único | Con `Promise.all()` y estado compartido, el fallo de un widget (ej. Inventario) ocultaba o bloqueaba los otros dos que sí habían cargado (ej. Ventas); cada sección ahora falla y se recupera de forma independiente                                          |

## Dominio del negocio

PAVH es para un negocio de **venta de pisos y materiales de construcción**. El cliente actualmente hace notas de venta, cotizaciones y ventas a mano — el objetivo del sistema es modernizar y digitalizar ese flujo completo, incluyendo poder generar un documento imprimible/compartible en vez de escribirlo a mano.

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
- **Se puede convertir en una Venta (POS) sin recapturar datos, permitiendo ajustar cantidades/precios antes de confirmar** — la cotización es, en esencia, un borrador de venta. Cotización y Venta comparten la misma estructura de líneas de producto/cantidad/precio, y una Venta puede tener un origen: "directa" o "desde cotización".
- `GET /api/quotes` soporta `?with=customer,quoteStatus` (whitelist explícita, valores desconocidos se ignoran silenciosamente) — resuelve N+1 detectado en
  QuoteResource (11 queries → 3 queries en listado de 5 registros). Patrón comma+whitelist, extensión del shortcut usado en `ProductController` (que hace match exacto de string) — no unificado entre ambos controllers todavía, queda como decisión pendiente si se quiere consistencia total.

### Frontend — Cotizaciones ✅ completo

- **Listado** (`QuotesView.vue`) ✅ — `DataTable`, búsqueda client-side por folio/cliente, badge de status con color (`statusClasses()` ahora vive en `src/lib/quoteStatus.js`, compartido con el detalle).
- **Creación** (`QuoteFormView.vue`) ✅ — cliente opcional, notas, líneas de producto con autocomplete de variante (`VariantAutocomplete.vue`, extraído a `src/components/widgets/autocompletes/` durante el trabajo de POS — búsqueda client-side sobre `inventory.fetchProducts()`, no existe endpoint de búsqueda de variantes por texto libre en el backend), fusión automática de variante duplicada, cantidad en m² con equivalente en cajas informativo, precio siempre server-resolved, aviso no bloqueante de stock insuficiente, totales en vivo. Selección de cliente vía `CustomerSearch.vue` (también extraído a widgets).
- **Detalle** (`QuoteDetailView.vue`) ✅ — header con folio/status/fecha, datos generales (cliente o "Sin cliente", notas), tabla de líneas (producto/color/medida vía `product_variant.product`, cantidad en m², cajas equivalentes, precio y subtotal **congelados** — no el precio de hoy), totales. Botón "Editar" habilitado solo si `status === 'Borrador'`; botón **"Convertir a venta" ya habilitado** (mismo criterio: solo si `status === 'Borrador'`), navega a `pos.sales.create?quote_id=` (ver módulo 3).
- **Edición** (`QuoteFormView.vue`, mismo componente que creación) ✅ — detecta modo edición vía `route.params.id`; si la cotización cargada no está en Borrador, redirige al detalle (el guard real vive en el backend, esto solo evita mostrar un form que el backend rechazaría); puebla `lines` directamente desde `currentQuote.items` (ya trae `product_variant.product` anidado, no depende de que Inventario esté cargado); al guardar llama `PUT` y redirige al detalle en vez de al listado.
- `stores/quotes.js`: `fetchQuote(id)` y `updateQuote(id, payload)` agregados junto a `fetchQuotes()`/`createQuote()`, mismo patrón `initialized` + mutación in-place.
- Pendiente (no bloqueante): cancelación de cotización — el status "Cancelada" ya existe en `quote_statuses` y el listado ya lo pinta, pero no hay endpoint ni UI que la dispare todavía.
- **PDF**: ✅ implementado — `QuoteController@downloadPdf` (`GET /api/quotes/{quote}/pdf`) reutiliza `DocumentPdfGenerator` sin cambios, misma estructura neutral que Venta. Botones "Descargar PDF"/"Compartir" en `QuoteDetailView.vue`, mismo criterio que `SaleDetailView.vue` (`navigator.canShare`, precarga del PDF al cargar la vista por el mismo motivo de Safari).
- Bug conocido no bloqueante: en `QuoteFormView.vue`, el `<form v-else>` depende de `generalError` — cualquier error de submit (incluso uno trivial como "Agrega al menos un producto") oculta todo el formulario hasta recargar la página. Detectado durante la extracción de `VariantAutocomplete`/`CustomerSearch` para POS, no corregido ahí para no reabrir el módulo de Cotizaciones sin motivo.
- Bug conocido no bloqueante: el listado cacheado de cotizaciones (`quotes.quotes` en el store) no refleja el nuevo estado "Convertida" tras convertir desde el detalle, hasta recargar el listado — el detalle sí refresca correctamente.
- **Lógica de descarga/compartir PDF extraída a composable**: `SaleDetailView.vue` y `QuoteDetailView.vue` compartían código idéntico (detección de `canShareFiles`, caché de la promesa del PDF, `downloadPdf`/`sharePdf`). Extraído a `src/composables/useDocumentPdf.js` — patrón a reutilizar si aparece un tercer consumidor de PDF en el futuro.
- Hay una guía completa del módulo (modelo de datos, flujo end-to-end, endpoints, decisiones) en el doc de Claude "Guía del módulo — Cotizaciones".

### Pendiente transversal

- Pase de UX/UI y estilos, módulo por módulo, una vez cerrada la cobertura
  funcional completa de todos los módulos (decisión de Justin — evitar
  pulir vistas que aún pueden cambiar de forma). Con Inventario, Cotizaciones y POS funcionalmente completos, este pase ya puede empezar cuando se decida.
- Bug conocido no bloqueante: la lógica de cálculo de rango de fecha (hoy/semana/mes, semana empezando en lunes, en hora local) está duplicada entre `SalesView.vue` y el nuevo `src/stores/dashboard.js` — detectado al construir el store del Dashboard, no corregido ahí para no reabrir `SalesView.vue` (módulo de POS ya cerrado) sin motivo. Candidato a extraerse a `src/lib/` (ej. `dateRanges.js`) cuando aparezca un tercer consumidor, o junto con el pase de UX/UI.
- Bug conocido no bloqueante: `formatCurrency`/`Intl.NumberFormat` está duplicado entre `SalesView.vue` (y otras vistas cerradas) y el nuevo `src/lib/formatCurrency.js` usado por los widgets del Dashboard — mismo criterio que el punto anterior, no se tocaron las vistas cerradas para consolidarlo.

**Decisión de modelado (resuelta e implementada):** `Quote` y `Sale` son **entidades separadas** (`quotes`/`quote_items` y `sales`/`sale_items`), no una sola tabla con `status`. Razones:

- Folios independientes por tipo de documento (`COT-0001` vs `V-0001`) — inviable de forma limpia con un solo autoincrement
- Consistente con la convención ya establecida de "acciones con efecto específico van en endpoint dedicado" (ver ajuste de stock) — convertir cotización en venta es una acción con efectos reales (descuenta stock), no un cambio de status genérico
- Reglas de edición/borrado distintas por naturaleza: una cotización es un borrador editable libremente mientras esté en status "Borrador"; una venta ya afectó inventario y no tiene PUT/DELETE
- Se acepta la duplicación estructural entre `quote_items`/`sale_items` (sin tabla polimórfica compartida) — consistente con "sin indirección extra"

**Backend implementado:**

- `customers`: CRUD sin `destroy` (no pedido), con búsqueda simple (`GET /api/customers?search=`) vía `scopeSearch()`, pensado para el buscador/alta inline del form de cotización/venta
- `quotes`/`quote_items`: folio server-side (`QuoteFolioGenerator`, formato `COT-0001`, `withTrashed()`), `unit_price` **siempre** resuelto del `price_per_m2` actual de la variante (ignora cualquier precio que mande el cliente), `subtotal`/`total` calculados server-side (`total = subtotal`, sin impuestos por ahora), edición (`PUT`, reemplaza todas las líneas) permitida **solo** mientras `status = "Borrador"` (guard clause en el controlador, 422 si no)
- `sales`/`sale_items`: folio independiente (`SaleFolioGenerator`, formato `V-0001`). A diferencia de `quotes`, `unit_price` **sí** viene del payload del frontend (el flujo de conversión permite ajustar precio antes de confirmar). Sin `PUT`/`DELETE` (no pedido)
- `GET /api/quotes/{id}/convert`: **solo lectura**, prellenar el form de nueva venta con las líneas de la cotización y el `price_per_m2` **actual** de cada variante (no el precio congelado en la cotización — se le muestra al usuario el precio de hoy). Rechaza 422 si la cotización no está en estado "Borrador" (ver `Quote::conversionBlockedMessage()` abajo), y también rechaza 422 si alguna línea referencia una variante soft-deleted (usa `withTrashed()` solo para nombrarla por `code` en el mensaje, nunca para dejarla pasar)
- `POST /api/sales`: valida stock suficiente (agregado por variante, cubre el caso de líneas duplicadas de la misma variante) **antes** de mutar nada, descuenta stock dentro de una transacción, y si trae `quote_id` marca esa cotización como "Convertida" automáticamente. Rechaza 422 vía el mismo `Quote::conversionBlockedMessage()` si el `quote_id` referenciado no está en "Borrador"
- **`Quote::conversionBlockedMessage()`** (método en el modelo): única fuente de verdad sobre si una cotización puede convertirse, usada tanto por `QuoteController@convert` como por `SaleController@store`. Rechaza cualquier estado que no sea "Borrador" (no solo "Convertida" — cubre "Cancelada" y cualquier estado futuro sin tocar los controladores de nuevo)
- **Conversión de unidades (m² ↔ cajas):** `quote_items.quantity`/`sale_items.quantity` viven en m² (consistente con que `unit_price` = `price_per_m2`), pero `stock_boxes` vive en cajas. Al crear una venta, se convierte con `ceil(quantity / m2_per_box)` por línea, agregando por variante antes de comparar contra stock disponible. Si `m2_per_box` es `null` en la variante, la línea se rechaza con 422 explícito — nunca se asume conversión 1:1. El dinero (`line_total`/`subtotal`/`total`) se calcula siempre sobre la cantidad exacta en m², independiente del redondeo hacia arriba usado para el descuento de stock
- `ProductVariant::hasSufficientStock()` / `decrementStock()`: extraídos como métodos reusables, usados tanto por el endpoint de ajuste de stock existente como por la creación de ventas — evita duplicar el guard de "Stock insuficiente"

**Clientes:** tabla `customers` simple (`name`, `phone`, `email`, sin `SoftDeletes` por ahora), no texto libre. `customer_id` es **nullable** en `quotes` y `sales` (se permite cotización/venta rápida sin capturar cliente). Módulo completo de clientes (edición, historial, etc.) queda diferido — esto es solo el catálogo básico + búsqueda.

### 3. Punto de Venta (POS) — ✅ completo (backend + frontend)

**Backend:**

- `sales`/`sale_items` completo: `GET /api/sales` (con `?with=customer,items` vía whitelist, filtro de rango de fecha `from`/`to` vía `Sale::scopeDateRange()`, orden por `created_at` descendente), `GET /api/sales/{id}` (carga `items.productVariant`, `items.productVariant.product`, `customer`, `quote`, todo con `withTrashed()` para poder ver/reimprimir una venta aunque la variante o el producto ya se hayan dado de baja), `POST /api/sales`
- `GET /api/sales/{id}/pdf`: genera y descarga el PDF de la nota de venta (ver `DocumentPdfGenerator` abajo)

**Frontend:**

- **Venta directa** (`SaleFormView.vue`) ✅ — cliente opcional (`CustomerSearch.vue`), líneas con `VariantAutocomplete.vue`, precio editable (a diferencia de Cotizaciones), fusión de variante duplicada, aviso no bloqueante de stock insuficiente (relevante por el sobrepedido, común en este negocio), sincronización in-place del stock de Inventario tras la venta (recalculado en el frontend con el mismo criterio de `ceil(quantity / m2_per_box)` que usa el backend, ya que `SaleResource` no regresa el stock restante). Al confirmar, resetea el form en lugar de navegar — pensado para flujo de mostrador ("siguiente cliente")
- **Convertir cotización a venta** ✅ — mismo `SaleFormView.vue`, detecta modo vía `?quote_id=` en la URL, prellena desde `GET /quotes/{id}/convert` (resolviendo cada `product_variant_id` contra `inventory.products`, con un reintento de `fetchProducts()` antes de bloquear si la variante no aparece en caché), bloquea con mensaje claro y formulario oculto si la cotización no está en "Borrador" o si alguna variante ya no está disponible. Al confirmar, redirige al detalle de la cotización en vez de resetear
- Botón "Convertir a venta" en `QuoteDetailView.vue` habilitado, visible/activo solo si `status === 'Borrador'`
- **Listado de ventas** (`SalesView.vue`) ✅ — `DataTable` con folio/cliente/fecha/total, filtros rápidos de fecha ("Todas"/"Hoy"/"Esta semana"/"Este mes", resueltos en backend vía `from`/`to`), búsqueda adicional client-side por folio/cliente sobre el resultado ya cargado. `stores/sales.js` con `fetchSales(params)`: **no** usa el patrón `initialized` como gate (cada cambio de filtro de fecha es una consulta legítima distinta), pero sí incluye un contador de petición para descartar respuestas tardías si el filtro cambia antes de que responda una anterior
- **Detalle de venta** (`SaleDetailView.vue`) ✅ — de solo lectura (sin edición ni borrado). Header con folio/fecha, datos generales (cliente o "Sin cliente"), tabla de líneas con totales. Botón "Descargar PDF" (llama a `GET /api/sales/{id}/pdf`) y botón "Compartir" vía Web Share API cuando el navegador soporta compartir archivos (`navigator.canShare`) — oculto si no hay soporte, en vez de mostrar un botón que fallaría. En móvil, el PDF se genera al cargar la vista (no al tocar "Compartir") porque Safari rechaza compartir si el primer tap tiene que esperar al servidor
- `PosView.vue` es un hub mínimo con dos accesos: "Nueva venta" y "Ver ventas"
- **Convención de frontend**: componentes reutilizables entre módulos viven en `src/components/widgets/<tipo>/` (ej. `autocompletes/`) — ver `AGENT.md`

**Generación de PDF (`DocumentPdfGenerator`):**

- Servicio genérico en `app/Services/DocumentPdfGenerator.php`, pensado para reusarse entre `Sale` y `Quote` — recibe una estructura de datos neutral (`document_type`, `folio`, `date`, `customer`, `items`, `subtotal`, `total`), nunca un modelo Eloquent directamente. Cada controlador arma su propia estructura desde su modelo
- Usa `barryvdh/laravel-dompdf`. Tamaño de página: **media carta por default** (consistente con el tamaño de nota que el cliente ya usa a mano), configurable como parámetro del servicio — sujeto a confirmarse con el cliente más adelante, cambiarlo no requiere tocar la plantilla
- Plantilla Blade (`resources/views/pdf/document.blade.php`) usa los tokens de color y tipografía de `AGENT.md`. **Gotcha de dompdf**: no lee `.woff2`, solo `.woff`, y descarta silenciosamente cualquier `@font-face` con `format()` distinto de `'truetype'` — las fuentes de `@fontsource` se copiaron a `.woff` en `resources/fonts/` y el `src` en la plantilla va sin `format('woff')` explícito (ver comentario en la plantilla, dejado ahí para que nadie lo "corrija")
- Aún no conectado a `Quote` — queda listo para hacerlo sin cambios al servicio, solo falta el endpoint y el botón en el frontend de Cotizaciones

### 4. Dashboard — 🚧 en progreso (backend: no requiere endpoints nuevos; frontend: en curso)

- No se identificó necesidad de un `GET /api/dashboard` dedicado: los tres widgets (resumen de ventas, alertas de stock bajo, cotizaciones activas) se resuelven reusando `/api/sales`, `/api/product-variants` y `/api/quotes` ya existentes. Se reconsiderará si el volumen de datos lo justifica más adelante.
- **Paso 1 (✅ hecho): `src/stores/dashboard.js`** — `fetchDashboardData()` dispara en paralelo con `Promise.allSettled()` (ver más abajo): (1) `salesSummary` (`today`/`week`/`month`), tres `GET /api/sales?from=&to=` sumando `total` client-side en centavos (evita error de punto flotante con el string decimal); (2) `lowStockVariants`, computed sobre `inventory.products` (respeta la caché `initialized` de Inventario) filtrando `low_stock === true`; (3) `activeQuotes`, `GET /api/quotes` filtrado client-side por `status === 'Borrador'` (el campo es `status`, string plano en `QuoteResource` — no `quoteStatus.name`). No usa el patrón `initialized`: se recalcula en cada visita, igual que `sales.js`, con contador de petición para descartar respuestas tardías.
- Las ventas y cotizaciones del dashboard se piden directo por axios, no vía `sales.fetchSales()`/`quotes.fetchQuotes()` — esas acciones escriben en el store de su propio listado (`SalesView.vue`/`QuotesView.vue`) y heredan su gate de caché/contador, pensado para ese caso de uso, no para 3 fetches paralelos independientes del dashboard.
- **Hallazgo corregido antes de seguir (módulo abierto, no diferido):** `fetchDashboardData()` usaba `Promise.all()` con un solo `loading`/`error` compartido — si fallaba cualquiera de los tres (ej. Inventario), el error tapaba también a Ventas o Cotizaciones aunque sí hubieran cargado bien. Se cambió a `Promise.allSettled()` con estado independiente por sección: `salesLoading`/`salesError`, `lowStockLoading`/`lowStockError`, `activeQuotesLoading`/`activeQuotesError`. Cada sección actualiza su propio estado en cuanto su fetch responde, sin esperar a las otras dos; si una falla, su mensaje nombra la sección (ej. "No se pudo cargar el resumen de ventas.") y sus datos se quedan con el valor anterior (o vacíos en la primera visita), sin afectar a las demás.
- **Paso 2 (✅ hecho): layout visible de `HomeView.vue`**, con datos de ejemplo (sin conectar al store todavía). Cuatro componentes en `src/views/dashboard/components/` (`SalesSummaryCards.vue`, `DashboardShortcuts.vue`, `LowStockWidget.vue`, `ActiveQuotesWidget.vue`), cada uno recibiendo sus datos por props (`DashboardShortcuts` sin props, accesos fijos a `pos.sales.create`/`quotes.create`/`inventory`). `src/lib/formatCurrency.js` extraído para los widgets (no toca el `formatCurrency` ya existente en vistas cerradas como `SalesView.vue`). `HomeView.vue` movido de `src/views/` a `src/views/dashboard/`, siguiendo la convención de `AGENT.md`.
- **Paso 3 (✅ hecho): resumen de ventas conectado.** `HomeView.vue` llama `dashboard.fetchDashboardData()` en `onMounted` (sin polling). `SalesSummaryCards.vue` recibe `salesLoading`/`salesError` desde `HomeView` pero mantiene sus propias props `loading`/`error` (no depende de los nombres del store) — mientras carga, cada card muestra una barra animada (`aria-busy`) en vez del monto; si hay error, un mensaje inline en `danger` reemplaza las 3 cards (en vez de mostrar `$0.00`, que se leería como "no hubo ventas"). `LowStockWidget` y `ActiveQuotesWidget` siguen con sus datos de ejemplo.
- Pendiente: paso 4 (widget de stock bajo), paso 5 (widget de cotizaciones activas) — ver desglose de pasos acordado con Justin.

### Pendiente antes de desplegar a producción

- **Migración de zona horaria**: `config/app.php` cambió de `UTC` a `America/Mexico_City` (necesario para que los filtros de fecha de ventas y el PDF usen el día local correctamente). Cualquier dato cargado en producción _antes_ de este cambio tiene `created_at`/`updated_at`/`deleted_at` en UTC sin marcar como tal — al desplegar, se necesita una migración que reste 6 horas a esas columnas en las tablas afectadas, o los registros viejos se van a ver desfasados.

### Implicaciones técnicas a resolver cuando se construya cada módulo

- ~~`Cotizacion` y `Venta` comparten estructura de líneas — evaluar si `Venta` es una entidad separada...~~ ✅ resuelto — ver spec de datos en la sección de Cotizaciones arriba. Las líneas (`quote_items`/`sale_items`) referencian `product_variants` directamente
- ~~Impresión: generar PDF carta/media carta (Laravel + librería PDF, ej. dompdf)~~ ✅ resuelto para Venta vía `DocumentPdfGenerator` — pendiente conectar el mismo servicio a Cotizaciones
- El diseño de `SoftDeletes` en variantes ya contempla que queden referenciadas desde cotizaciones/ventas sin romperse

## Roadmap

1. ~~Sistema de diseño~~ ✅ — definido en `AGENT.md` (paleta, tipografía, layout)
2. ~~Dashboard real~~ - store de datos, layout visible y resumen de ventas, stock bajo y cotizaciones activas
3. ~~Limpieza de scaffold sin usar~~ ✅
4. ~~Navegación principal (sidebar + topbar)~~ ✅
5. ~~Módulo de Inventario~~ ✅ — backend y frontend completos (catálogo, CRUD de producto y variante, ajuste de stock)
6. ~~Módulo de Cotizaciones~~ ✅ — backend y frontend completos (listar, crear, ver detalle, editar, PDF). Pendiente no bloqueante: cancelación
7. ~~Módulo de Punto de Venta~~ ✅ — venta directa, conversión desde cotización, listado con filtros de fecha, detalle con PDF descargable/compartible
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
feat(sales): :sparkles: add PDF download endpoint for sales via DocumentPdfGenerator
fix(sales): :bug: reject converting or confirming a cancelled quote
config(app): :wrench: switch application timezone to America/Mexico_City
feat(dashboard): :sparkles: add dashboard store with sales summary, low stock and active quotes
feat(dashboard): :sparkles: add HomeView layout with placeholder dashboard widgets
fix(dashboard): :bug: use Promise.allSettled with per-widget loading/error state
feat(dashboard): :sparkles: connect sales summary widget to dashboard store
```
