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

### 2. Cotizaciones — 🚧 siguiente módulo
- Generar cotización seleccionando productos del catálogo de Inventario
- Imprimir cotización en tamaño carta/media carta
- **Se puede convertir directamente en una Venta (POS) sin recapturar datos** — la cotización es, en esencia, un borrador de venta. Esto implica que Cotización y Venta deben compartir la misma estructura de líneas de producto/cantidad/precio, y que una Venta puede tener un origen: "directa" o "desde cotización".

### 3. Punto de Venta (POS)
- Registrar ventas, ya sea directas o convertidas desde una cotización existente
- Descontar stock de Inventario automáticamente al concretar la venta
- Imprimir nota de venta en **tamaño carta/media carta** (no ticket térmico — esto descarta impresoras térmicas de 58mm/80mm como requisito, se resuelve con impresión estándar/PDF)

### Implicaciones técnicas a resolver cuando se construya cada módulo
- `Cotizacion` y `Venta` comparten estructura de líneas — evaluar si `Venta` es una entidad separada con referencia opcional a `Cotizacion`, o si `Cotizacion` es un estado de `Venta` (pendiente / convertida). Las líneas probablemente referencian `product_variants` directamente (ya que es la unidad con precio y stock real)
- Impresión: generar PDF carta/media carta (Laravel + librería PDF, ej. dompdf) — pendiente de decidir en detalle cuando se llegue a este módulo
- El diseño de `SoftDeletes` en variantes ya contempla que queden referenciadas desde cotizaciones/ventas sin romperse

## Roadmap

1. ~~Sistema de diseño~~ ✅ — definido en `AGENT.md` (paleta, tipografía, layout)
2. Dashboard real (reemplazar `HomeView.vue` placeholder) — pendiente
3. ~~Limpieza de scaffold sin usar~~ ✅
4. ~~Navegación principal (sidebar + topbar)~~ ✅
5. ~~Módulo de Inventario~~ ✅ — backend y frontend completos (catálogo, CRUD de producto y variante, ajuste de stock)
6. **Módulo de Cotizaciones** (depende del catálogo de Inventario, ya listo) — siguiente paso
7. Módulo de Punto de Venta (depende de Cotizaciones e Inventario)
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