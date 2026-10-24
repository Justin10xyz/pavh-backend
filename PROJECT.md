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
| 6   | Reportes                          |   ✅    |    ✅    | Módulo completo (datos + CSV + PDF + frontend con rango personalizado) en `feat/reports` (rama a partir de `develop`), verificado en navegador 23-oct-2026; falta mergear a `develop` — ver `claude/PLAN-REPORTES.md` |
| —   | Pase de UX/UI transversal         |    —    |    —     | En curso — Inventario, Cotizaciones, Ventas, Clientes, Configuración, Dashboard y Login hechos |

## Hitos recientes

- **23-oct-2026 — Reportes (Paso 3, frontend, completo)**: `ReportsView.vue` en `/reportes`, store `reports.js` (mismo patrón sin `initialized` que `sales.js`), entrada en sidebar entre "Comprobantes" y "Punto de venta". Tabla con columna final "ver" (paridad con `SalesView.vue`). Selector de periodo con **dos modos**: mes calendario (`<input type="month">`, como se planeó originalmente) y **rango personalizado** (dos `<input type="date">`), agregado en la misma iteración tras confirmar con Justin que valía la pena exponer de una vez el rango arbitrario que el backend ya soportaba desde el Paso 1 — el modo se decide por la presencia de `from`/`to` en la URL, con validación de rango incompleto/inválido que evita consultar al backend con datos malos. Descarga de PDF/CSV con lógica propia (no reusa `useDocumentPdf`, pensado para un documento con id+folio y compartir, no para un rango de fechas con dos formatos). **Con esto, el módulo Reportes queda completo** (backend + frontend). Verificado en navegador por Justin. Pendiente: mergear `feat/reports` a `develop`, y decidir si se agrega algún reporte nuevo (ver Pendientes).
- **23-oct-2026 — Reportes (Paso 2b, backend)**: endpoint `GET /api/reports/comprobantes/pdf?from=&to=` en `feat/reports` — PDF carta vertical (reporte interno, no el talonario físico), servicio y plantilla nuevos (`ComprobanteReportPdfGenerator`), reusa `PdfSheetLayout` tal cual (capacidades medidas empíricamente con `dompdf`, los valores iniciales del plan quedaron muy por debajo de lo real), `SpanishMonthName` nuevo (código propio, sin librería de localización) para el título del periodo — cubre el caso de un rango que no es un mes calendario completo. Encabezado ajustado tras revisión a solo logo INNOVA, sin la línea "SUB-DISTRIBUIDOR AUTORIZADO/Interceramic/Representante" que trae `pdf.partials.brand` (pensada para documentos que sí ve el cliente) — ese partial se dejó sin modificar, la plantilla nueva construye su propio bloque de logo. Con esto, el backend completo del módulo Reportes quedó cerrado (datos + CSV + PDF). 386 tests pasando.
- **23-oct-2026 — Reportes (Paso 2a, backend)**: endpoint `GET /api/reports/comprobantes/csv?from=&to=` en `feat/reports`, exportación streameada (`streamDownload`, sin construir el archivo completo en memoria) con BOM UTF-8 (acentos/ñ correctos en Excel/Windows), fila de totales calculada con el mismo `summaryFor()` que usa el JSON del Paso 1. `fputcsv(..., escape: '')` para seguir el escapado estándar de CSV (comillas) en vez del backslash por default de PHP, que rompía nombres de cliente terminados en `\` y generaba un deprecation warning en PHP 8.4.
- **23-oct-2026 — Reportes (Paso 1, backend)**: endpoint `GET /api/reports/comprobantes?from=&to=` — listado + totales de Comprobantes por rango de fecha (filtra por `comprobantes.date`, no `created_at`), `Report/` como subcarpeta de módulo (Controller, Form Request, Resource propio sin reusar `ComprobanteResource`). `summary.subtotal`/`summary.total` usan `number_format()` en vez de castear directo el resultado de `sum()` (que no respeta el cast `decimal` del modelo).
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
| Reportes usa `number_format()` para los totales del `summary`, no un cast directo del resultado de `sum()` | `sum()` de Eloquent no respeta el cast `decimal` del modelo (devuelve float nativo de PHP) — perdía formato/precisión frente a `items[]`, que sí viene casteado |
| CSV/PDF de Reportes usan `fputcsv(..., escape: '')` | El escape por default de PHP (backslash) no es el estándar CSV y rompe nombres de cliente que terminan en `\`; además genera deprecation warning en PHP 8.4 |
| CSV/PDF de Reportes usan `$customer->name ?? 'Sin cliente'` sin `?->` | Verificado que no genera warning de PHP (`??` evalúa su operando izquierdo en modo isset, suprime el warning en toda la cadena); PHPStan señala correctamente que `?->` sería redundante ahí |
| PDF de Reportes no reusa `pdf.partials.brand` | Ese partial junta el logo INNOVA con branding de Interceramic/Representante pensado para documentos que sí ve el cliente; el reporte es interno, así que construye su propio bloque de solo-logo sin modificar el partial compartido |
| Descarga de PDF/CSV de Reportes nunca se deshabilita por falta de datos | Un reporte "sin comprobantes" también es un resultado válido (constancia de que un mes no tuvo movimiento) |
| Reportes expone mes calendario y rango personalizado, no solo mes | El backend ya aceptaba cualquier rango desde el Paso 1; se decidió exponerlo de una vez en el frontend en vez de dejarlo como capacidad latente |
| `ReportsView.vue` es directamente el Reporte de Comprobantes, sin una pantalla "índice" de reportes | Solo existe un reporte por ahora — una estructura de hub para reportes futuros sería especulativa; se construye cuando se confirme un segundo reporte real |

Historial completo de decisiones en `decisions-and-learnings.md`.

## Pendientes

**No bloqueantes, por módulo:**

- **Inventario**: importador de listas de precios de proveedores; borrado de producto/medida completos solo aplica al Catálogo de pisos, no a "Otros productos"; paginación del listado de ventas; hub de POS con resumen del día.
- **Cotizaciones**: URLs `/cotizaciones/...` y algunos mensajes 422 del backend siguen diciendo "cotización" tras el renombre visible a "Presupuestos"; datos reales de RFC/idCIF/QR en Configuración → Datos del negocio (el cliente aún no los proporciona); verificar en navegador que tras la fusión del 22-oct no quedó la tabla de líneas duplicada en ningún detalle.
- **Comprobante Fiscal**: `app/Http/Controllers/Comprobante/ComprobanteController.php` falla `pint --test` (formato) — preexistente, detectado el 23-oct-2026 al construir Reportes (Paso 1) sin tocar ese archivo; no corregido de pasada, queda como paso aparte nombrado.
- **Reportes**:
  - Verificar visualmente un PDF de 2-3 hojas (~70-140 comprobantes) una vez exista un mes real con ese volumen, o generando una prueba manual — las capacidades de `PdfSheetLayout` se midieron por conteo de páginas, no visualmente, por falta de rasterizador en el entorno de construcción.
  - Mergear `feat/reports` a `develop` — se decidió deliberadamente construir el frontend completo (incluido el rango personalizado) antes del merge.
  - **Decidir si se agrega algún reporte nuevo al módulo** (sugerido 2026-10-23, sin decidir): Ventas por periodo, Presupuestos convertidos vs. no convertidos, Inventario con bajo stock/rotación, Top clientes o productos. Ver `claude/PLAN-REPORTES.md` §13.
  - El botón "Volver" al abrir un Comprobante desde Reportes regresa a `comprobantes.index`, no a `/reportes` — comportamiento existente del detalle, aceptado tal cual; cambiarlo sería un ajuste aparte en `ComprobanteDetailView.vue`, no pedido.
- **Clientes**: "Nueva cotización"/"Nueva venta" desde el detalle del cliente con el cliente ya elegido.
- **Autenticación**: "Recordar sesión" y restablecer contraseña (sin endpoint detrás todavía).
- **Transversal**: título de pestaña genérico en vistas de detalle; `site.webmanifest` faltante.

**Antes de desplegar a producción:** confirmar que el despliegue vigente ya corresponde al `main` fusionado el 22-oct, y mergear `feat/reports` cuando esté listo.

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
10. ~~Módulo de Reportes~~ ✅ (2026-10-23, backend + frontend) — falta mergear a `develop`; posibles reportes adicionales sin decidir, ver `claude/PLAN-REPORTES.md`

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
