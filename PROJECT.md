# PAVH

## Visión

PAVH es una aplicación web tipo **dashboard / panel administrativo**, en desarrollo temprano. Está construida como dos repositorios independientes que se comunican vía API REST + cookies de sesión.

## Dominio del negocio

PAVH es para un negocio de **venta de pisos y materiales de construcción**. El cliente antes hacía notas de venta, cotizaciones y ventas a mano — el sistema digitaliza ese flujo completo, incluyendo poder generar un documento imprimible/compartible.

Estructura de catálogo: un **producto** es la línea/colección (ej. "Creato"), una **variante** es la combinación color+medida específica (ej. Creato/Taupe/60x120) — es la unidad real que se vende, tiene precio propio y stock. **Se vende por caja completa**, aunque el precio se publique por m² (`price_per_box` = `price_per_m2 × m2_per_box`); el vendedor calcula cuántas cajas cubren los m² pedidos y se cobran los m² de las cajas entregadas. Algunos pisos (mosaicos, decorados) **se venden por pieza**: sin m², PEI ni ETT, y la cantidad de línea son piezas sueltas.

## Arquitectura

```
pavh-backend/    Laravel 11 · API REST pura · Sanctum (SPA cookie-based auth) · MySQL
pavh-frontend/   Vue 3 + Vite · Pinia · Vue Router · Tailwind CSS · Axios · PrimeVue 4 (unstyled)
```

- **Auth**: Sanctum en modo SPA (cookies HttpOnly, no tokens en localStorage).
- **Entorno**: instalación nativa, sin Docker ni Valet (Valet genera dominios `.test` que rompen `SameSite=Lax`). `php artisan serve` (:8000) + `npm run dev` (:5173).
- **Producción**: backend y frontend bajo el mismo dominio, nginx como reverse proxy, backend expuesto bajo `/api` — evita problemas de cookies cross-site. Desplegado en `innovapisos.app` (ver `claude/GUIA-INFRAESTRUCTURA-JUSTIN.md`).
- **Patrón de capa de datos (backend)**: controladores delgados + Query Scopes + Form Requests + API Resources. Deliberadamente **sin Repository Pattern**. Validaciones que dependen del modelo bindeado por ruta viven como guard clause en el controlador, no en el Form Request.
- **Componentes UI (frontend)**: PrimeVue 4 unstyled, estilizado con los tokens del sistema de diseño. En modo unstyled no hay clases `p-*`; se usa `data-pc-section` para seleccionar partes internas.
- **Zona horaria**: `America/Mexico_City`.

Detalle de convenciones, gotchas y sistema de diseño completo en `AGENT.md`; detalle día a día del comportamiento de Inventario en `guia-inventario.md`.

## Estado de módulos

| #   | Módulo                           | Backend | Frontend | Notas                                                                        |
| --- | --------------------------------- | :-----: | :------: | ----------------------------------------------------------------------------- |
| —   | Autenticación                     |   ✅    |    ✅    | 4 pendientes no bloqueantes                                                    |
| 1   | Inventario                        |   ✅    |    ✅    | 4 pendientes no bloqueantes                                                    |
| 2   | Cotizaciones ("Presupuestos")      |   ✅    |    ✅    | —                                                                              |
| 3   | Punto de Venta (POS)              |   ✅    |    ✅    | —                                                                              |
| 4   | Dashboard                         |   ✅    |    ✅    | —                                                                              |
| 5   | Clientes                          |   ✅    |    ✅    | —                                                                              |
| —   | Configuración                     |   ✅    |    ✅    | 1 pendiente no bloqueante                                                      |
| —   | Comprobante Fiscal Simplificado   |   ✅    |    ✅    | datos reales de RFC/idCIF/QR pendientes (ver `claude/GUIA-TECNICA-COMPROBANTE-FISCAL.md`) |
| —   | Pase de UX/UI transversal         |    —    |    —     | En curso — Inventario, Cotizaciones, Ventas, Clientes, Configuración, Dashboard y Login hechos |

## Hitos recientes

- **21-oct-2026 — Inventario**: Estilos de piso (`FloorStyle`, catálogo espejo de Categoría/Proveedor con CRUD + soft delete + bloqueo si tiene productos activos), Número de caras (`face_count`, aplica a pisos por m² y por pieza), borrado de medida completa y de producto completo (no bloqueante, mismo criterio que Clientes).
- **22-oct-2026 — Fusión `develop`→`main`**: unificó lo anterior con el frontend de Comprobante Fiscal Simplificado, Datos del negocio y campos de pago del Presupuesto (descuento/envío/anticipo/crédito, marcar como pagado) que solo vivían en `develop` desde el 19/20-oct (el backend de todo esto ya estaba en `main`). Único conflicto real: `QuoteDetailView.vue` — `develop` había reemplazado la tabla de líneas por el componente compartido nuevo `DocumentItemsTable.vue` (usado por Cotización y Comprobante; Venta conserva su propia tabla), al que le faltaba el badge de caras agregado el 21-oct. Se resolvió quedándose con `DocumentItemsTable.vue` y agregándole ese badge. Pendiente de confirmar que el despliegue en producción ya corresponde a este `main` fusionado.

Detalle técnico completo de ambos hitos (migraciones, endpoints, componentes) en el historial de commits de `pavh-backend`/`pavh-frontend`.

## Decisiones clave

| Decisión | Razón |
| --- | --- |
| Sin Repository Pattern | No resuelve ningún problema real con el tamaño actual del catálogo |
| `Quote`/`Sale` como entidades separadas, no una tabla con `status` | Folios independientes por tipo, reglas de edición distintas, convertir cotización en venta es una acción con efectos reales (descuenta stock) |
| Venta por caja completa (`boxes` es la cantidad real, `quantity` se deriva) | El proveedor vende por caja aunque publique precio por m²; evita vender fracciones de caja |
| Borrado no bloqueante en Clientes y Productos, bloqueante en Categoría/Proveedor/Estilo de piso | Un cliente o producto borrado sigue mostrando su nombre en documentos históricos ya congelados; una categoría/proveedor/estilo borrado sí dejaría huérfanos a otros registros activos |
| `face_count` aplica a pisos por m² y por pieza (a diferencia de PEI/ETT) | Es una característica física de la caja, no depende de cómo se vende el piso |
| Al fusionar `develop`→`main`, se conservó el componente compartido `DocumentItemsTable.vue` en vez de volver a la tabla duplicada que tenía `main` | Ya resolvía Cotización + Comprobante con una sola fuente de verdad |

Historial completo de decisiones en `decisions-and-learnings.md`.

## Pendientes

**No bloqueantes, por módulo:**

- **Inventario**: importador de listas de precios de proveedores; borrado de producto/medida completos solo aplica al Catálogo de pisos, no a "Otros productos"; paginación del listado de ventas; hub de POS con resumen del día.
- **Cotizaciones**: URLs `/cotizaciones/...` y algunos mensajes 422 del backend siguen diciendo "cotización" tras el renombre visible a "Presupuestos"; datos reales de RFC/idCIF/QR en Configuración → Datos del negocio (el cliente aún no los proporciona); verificar en navegador que tras la fusión del 22-oct no quedó la tabla de líneas duplicada en ningún detalle.
- **Clientes**: "Nueva cotización"/"Nueva venta" desde el detalle del cliente con el cliente ya elegido.
- **Autenticación**: "Recordar sesión" y restablecer contraseña (sin endpoint detrás todavía).
- **Transversal**: título de pestaña genérico en vistas de detalle; `site.webmanifest` faltante.

**Antes de desplegar a producción:** confirmar que el despliegue vigente ya corresponde al `main` fusionado el 22-oct.

## Roadmap

1. ~~Sistema de diseño~~ ✅
2. ~~Módulo de Inventario~~ ✅
3. ~~Módulo de Cotizaciones~~ ✅ (incluido Comprobante Fiscal Simplificado)
4. ~~Módulo de Punto de Venta~~ ✅
5. ~~Dashboard real~~ ✅
6. ~~Módulo de Clientes~~ ✅
7. ~~Despliegue a producción~~ ✅ — `innovapisos.app`
8. ~~Fusión `develop`→`main`~~ ✅ (2026-10-22)
9. Pase de UX/UI transversal — en curso

Roles y permisos **no está en el roadmap** — se decidió no implementarlo.

## Convenciones de commits

Conventional Commits, descripciones en inglés, con gitmoji — un ejemplo por tipo:

```
feat(inventory): :sparkles: add floor styles management to settings and product forms
fix(sales): :bug: reject converting or confirming a cancelled quote
style(quotes): :lipstick: rename quotes module to presupuestos in user-facing text
refactor(inventory): :recycle: split ProductFormView into section components and productForm helpers
config(app): :wrench: switch application timezone to America/Mexico_City
hotfix(auth): :ambulance: fix logout using wrong guard
test(sales): :white_check_mark: add end-to-end test for mixed quote-to-sale flow
docs: :memo: document piece-based sales and component split rule
```

Historial completo de commits en `pavh-backend`/`pavh-frontend` — este documento ya no reproduce la lista completa para evitar que se desactualice.
