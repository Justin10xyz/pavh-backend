# PAVH

## Visión

PAVH es una aplicación web tipo **dashboard / panel administrativo**, en desarrollo temprano. Está construida como dos repositorios independientes que se comunican vía API REST + cookies de sesión.

## Dominio del negocio

PAVH es para un negocio de **venta de pisos y materiales de construcción**. El cliente antes hacía notas de venta, cotizaciones y ventas a mano — el sistema digitaliza ese flujo completo, incluyendo poder generar un documento imprimible/compartible. El sistema se organiza alrededor de dos documentos de negocio: **Presupuesto** (cotización) y **Comprobante Fiscal Simplificado** (lo que cierra un presupuesto pagado). El cliente confirmó que no usará Punto de Venta ni ventas directas — ver Hitos recientes, 26-oct-2026.

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
| 2   | Cotizaciones ("Presupuestos")      |   ✅    |    ✅    | Dos cambios del 23-oct-2026 sin probar en navegador ni commitear: borrado (soft delete) con reglas de bloqueo, y nuevo estado explícito `Pagada` en el catálogo de estados |
| 4   | Dashboard                         |   ✅    |    ✅    | —                                                                              |
| 5   | Clientes                          |   ✅    |    ✅    | Falta mostrar historial de Comprobantes en el detalle (ver Pendientes)        |
| —   | Configuración                     |   ✅    |    ✅    | 1 pendiente no bloqueante                                                      |
| —   | Comprobante Fiscal Simplificado   |   ✅    |    ✅    | completo, incluida la Cédula de Identificación Fiscal real en el PDF (ver `claude/GUIA-TECNICA-COMPROBANTE-FISCAL.md`) |
| 6   | Reportes                          |   ✅    |    ✅    | Módulo completo (datos + CSV + PDF + frontend con rango personalizado) en `feat/reports` (rama a partir de `develop`), verificado en navegador 23-oct-2026; falta mergear a `develop` — ver `claude/PLAN-REPORTES.md` |
| —   | Pase de UX/UI transversal         |    —    |    —     | En curso — Inventario, Cotizaciones, Clientes, Configuración, Dashboard y Login hechos |

## Hitos recientes

- **26-oct-2026 — Eliminación completa del módulo Punto de Venta (Ventas)**: el cliente confirmó que no usará Ventas/POS en absoluto — el sistema se queda solo con Presupuestos y Comprobantes. Como no existían ventas reales en producción, se eliminó por completo (no se ocultó ni se migró data): `Sale`, `SaleItem`, `SaleController`, `StoreSaleRequest`, `SaleResource`, `SaleItemResource`, `SaleFolioGenerator`, `DocumentPdfGenerator` y su vista (sus notas de fuentes/cantidades se rescataron a `sheet-styles.blade.php`/`items-table.blade.php`, compartidos ahora por `QuotePdfGenerator` y `ComprobantePdfGenerator`), rutas `/api/sales`, `Customer::sales()`, `sales_count` de los `delete-summary`. `StockMovement` perdió el origen/relación `sale` — los movimientos históricos con ese origen se conservan en la base, pero sin folio. Migración nueva dropeó `sales`/`sale_items` y `stock_movements.sale_id`, reasignó a `Pagada` cualquier presupuesto que estuviera en `Convertida` y quitó ese estado del catálogo `quote_statuses` (era exclusivo del flujo de venta). El widget "productos más vendidos" del Dashboard **no se eliminó**: se reescribió para sumar líneas de cotizaciones pagadas en vez de `sale_items`, mismo formato de respuesta. En frontend se quitaron rutas/sidebar/vistas de POS, el botón "Convertir a venta" y el estado "Convertida" de Presupuesto, las tarjetas de resumen de ventas del Dashboard, y el historial de ventas del detalle de Cliente. Verificado con la suite completa de tests, Pint y build/lint del frontend, y revisado en navegador. Plan completo con cada paso en `claude/PLAN-ELIMINAR-VENTAS.md`. **Pendiente nuevo descubierto en la revisión**: el detalle de Cliente nunca mostró un historial de Comprobantes (solo Presupuestos y, hasta ahora, Ventas) — sin decidir si se construye.
- **23-oct-2026 — Presupuestos: estado explícito `Pagada` en el catálogo**: antes "pagado" solo vivía como el campo `paid_at`; ahora el catálogo de estados de Presupuesto (`QuoteStatusSeeder`) queda como Borrador, Pagada, Convertida, Cancelada. Migración nueva (`2026_10_23_000001_add_pagada_quote_status.php`) crea el estado `Pagada` y pasa a ese estado todas las cotizaciones existentes con `paid_at` ya puesto, incluidas las dadas de baja; solo inserta el registro si el catálogo ya tiene datos (es decir, en la BD real), así una BD nueva lo recibe del seeder sin chocar con el índice unique ni con los tests, que arman su propio catálogo; el `down()` regresa esas cotizaciones a Borrador y borra el estado. `QuoteController::markPaid` asigna `Pagada` dentro de la misma transacción que descuenta el stock. En `QuoteController::update` se invirtió el orden de las validaciones para que revise `paid_at` antes que el estado — si no, un presupuesto pagado respondía "Solo se pueden editar presupuestos en estado Borrador" en vez de avisar que ya fue pagado. Sin cambios en los guards de convertir, cancelar, marcar como pagada y eliminar: todos siguen revisando `paid_at` primero, y cualquier estado distinto de Borrador cae en el default que rechaza. Suite completa pasa (390 tests), Pint sin cambios pendientes. En el frontend, `src/lib/quoteStatus.js` agrega `Pagada` a `QUOTE_STATUSES`/`STATUS_LABELS` (etiqueta "Pagado", mismo azul que ya tenía el badge) — como `QuotesView` arma su filtro rápido a partir de `QUOTE_STATUSES`, el filtro "Pagado" aparece solo; `quotes.js` → `markQuotePaid()` ahora también copia `status`, así la tabla y el detalle cambian a "Pagado" sin recargar. Se quitó el badge dedicado: columna "Pagado" eliminada de `QuotesView.vue` (sin él solo mostraba un guion), quitado del encabezado de `QuoteDetailView.vue` (la línea "Pagado el {fecha}" se queda), y `QuotePaidBadge.vue` borrado por quedar sin uso. `isEditable` pasó a ser solo `status === 'Borrador'` (antes dependía de otra condición); `canConvertToComprobante` y `canDelete` siguen usando `paid_at`, no `status`. Dashboard sin cambios — su filtro `status === 'Borrador'` ya dejaba fuera a los pagados. **Build del frontend pasa pero el flujo todavía no se probó en el navegador; sin commit todavía (ni backend ni frontend).** *(Nota 26-oct-2026: el estado `Convertida` mencionado aquí ya no existe — se eliminó en la limpieza de Ventas.)*
- **23-oct-2026 — Presupuestos: borrado (soft delete) con reglas de bloqueo**: `Quote::deletionBlockedMessage()` nuevo (mismo patrón que los demás `*BlockedMessage()`) bloquea el borrado con mensaje 422 en tres casos: el presupuesto está pagado (se quedó el mensaje que ya existía), tiene una venta asociada (aunque esa venta esté dada de baja), o tiene un comprobante asociado (aunque esté dado de baja). `QuoteController::destroy` usa ese método y ya no borra las líneas manualmente — como el borrado del presupuesto ya es soft delete, las líneas (`quote_items`) se quedan en la base y un `restore()` regresa el presupuesto completo con sus líneas; confirmado que nada consulta `quote_items` sin pasar por su presupuesto, así que dejarlas no afecta reportes ni listados. Tests actualizados en `QuoteApiTest` (el test de borrado ahora comprueba que las líneas se conservan y que `restore()` funciona, más tests nuevos para presupuesto cancelado que sí se borra, con venta y con venta dada de baja, ambos 422) — `QuoteApiTest` y `QuotePaymentTest` pasan: 66 tests, Pint sin cambios pendientes. En el frontend, `src/stores/quotes.js` agrega `deleteQuote(id)` (DELETE + quita el presupuesto del listado sin recargarlo + limpia `currentQuote` si era el borrado) y `QuoteDetailView.vue` agrega el botón de borrado — solo ícono, rojo como "Cancelar presupuesto", primero en la fila de acciones — con la misma regla `canDelete` que `deletionBlockedMessage()` del backend (no pagado, sin venta, sin comprobante; aparece en Borrador y en Cancelada), confirmación con el mismo diálogo que ya usa la vista, aviso de éxito y regreso al listado con el filtro que tenía, y error 422 mostrado en `deleteError` (debajo de `cancelError`, se limpia al cambiar de presupuesto). El Dashboard (paso 4) no necesitó cambios: `fetchActiveQuotes()` ya consulta el endpoint directo en cada visita sin `initialized`. **Build del frontend pasa pero el flujo de borrado aún no se probó en el navegador; sin commit todavía (ni backend ni frontend).** *(Nota 26-oct-2026: "tiene una venta asociada" ya no aplica — el bloqueo por venta se quitó junto con el módulo.)*
- **23-oct-2026 — Comprobante Fiscal Simplificado (Cédula de Identificación Fiscal real)**: cargados en Configuración → Datos del negocio los datos reales de RFC (`LOLP750330CQ8`), idCIF (`14080804512`) y Lugar de expedición (`Villa Hidalgo, Jalisco`), confirmados contra la Constancia de Situación Fiscal oficial de Pedro López Laris (emitida 22-jul-2026). El recuadro de Cédula de Identificación Fiscal del PDF del Comprobante ya no imprime RFC/idCIF como texto plano: ahora embebe una imagen fija (`resources/images/branding/cedula-fiscal.png`, escala de grises, recortada de esa misma Constancia — logos Hacienda/SAT, QR, RFC, nombre e idCIF reales), igual de fija que el logo de INNOVA y "*REPRESENTANTE: Pedro López Laris", sin depender de `business_settings.tax_id`/`tax_id_number` para decidir si se muestra. Tests de feature actualizados. Verificado con PDFs de 2 y 6 líneas, y con un Comprobante real (`CF-0001`). Con esto, el único pendiente conocido del módulo queda cerrado — ver `claude/GUIA-TECNICA-COMPROBANTE-FISCAL.md`.
- **23-oct-2026 — Reportes (Paso 3, frontend, completo)**: `ReportsView.vue` en `/reportes`, store `reports.js` (mismo patrón sin `initialized` que `sales.js`), entrada en sidebar entre "Comprobantes" y "Punto de venta". Tabla con columna final "ver" (paridad con `SalesView.vue`). Selector de periodo con **dos modos**: mes calendario (`<input type="month">`, como se planeó originalmente) y **rango personalizado** (dos `<input type="date">`), agregado en la misma iteración tras confirmar con Justin que valía la pena exponer de una vez el rango arbitrario que el backend ya soportaba desde el Paso 1 — el modo se decide por la presencia de `from`/`to` en la URL, con validación de rango incompleto/inválido que evita consultar al backend con datos malos. Descarga de PDF/CSV con lógica propia (no reusa `useDocumentPdf`, pensado para un documento con id+folio y compartir, no para un rango de fechas con dos formatos). **Con esto, el módulo Reportes queda completo** (backend + frontend). Verificado en navegador por Justin. Pendiente: mergear `feat/reports` a `develop`, y decidir si se agrega algún reporte nuevo (ver Pendientes). *(Nota 26-oct-2026: `sales.js`/`SalesView.vue` y la entrada "Punto de venta" del sidebar citados aquí ya no existen — eliminados junto con el módulo Ventas.)*
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
| Eliminar Venta/POS en vez de solo bajarle prioridad (26-oct-2026) | El cliente confirmó que nunca usará ese flujo; sin ventas reales en producción, eliminar código/tablas no tiene costo de datos y evita mantener una ruta muerta |
| Venta por caja completa (`boxes` es la cantidad real, `quantity` se deriva) | El proveedor vende por caja aunque publique precio por m²; evita vender fracciones de caja |
| Borrado no bloqueante en Clientes y Productos, bloqueante en Categoría/Proveedor/Estilo de piso | Un cliente o producto borrado sigue mostrando su nombre en documentos históricos ya congelados; una categoría/proveedor/estilo borrado sí dejaría huérfanos a otros registros activos |
| Borrado de Presupuesto bloqueado si está pagado o tiene comprobante (aunque ese comprobante esté dado de baja) | Un presupuesto pagado o ya convertido a comprobante es un documento con efectos reales ya ocurridos (stock descontado, documento generado); borrarlo rompería la trazabilidad aunque el registro relacionado esté dado de baja |
| Borrado de Presupuesto es soft delete y no borra sus líneas (`quote_items`) | El borrado del presupuesto ya es soft delete; dejar las líneas permite que `restore()` regrese el presupuesto completo, y no afecta reportes/listados porque nada las consulta sin pasar por su presupuesto |
| El estado "pagado" de un Presupuesto es un valor explícito (`Pagada`) en el catálogo de estados, no solo el campo `paid_at` | Permite que `isEditable` y el filtro rápido de `QuotesView` se basen directamente en `status`, igual que Borrador/Cancelada, en vez de una condición aparte; `paid_at` se conserva y sigue siendo la fuente de verdad para los guards de cancelar/marcar como pagada/eliminar |
| `QuoteController::update` revisa `paid_at` antes que `status` | Si revisara el estado primero, un presupuesto pagado respondía "Solo se pueden editar presupuestos en estado Borrador" en vez de avisar que ya fue pagado — el mensaje debe reflejar la causa real del bloqueo |
| `face_count` aplica a pisos por m² y por pieza (a diferencia de PEI/ETT) | Es una característica física de la caja, no depende de cómo se vende el piso |
| Al fusionar `develop`→`main`, se conservó el componente compartido `DocumentItemsTable.vue` en vez de volver a la tabla duplicada que tenía `main` | Ya resolvía Cotización + Comprobante con una sola fuente de verdad |
| Reportes usa `number_format()` para los totales del `summary`, no un cast directo del resultado de `sum()` | `sum()` de Eloquent no respeta el cast `decimal` del modelo (devuelve float nativo de PHP) — perdía formato/precisión frente a `items[]`, que sí viene casteado |
| CSV/PDF de Reportes usan `fputcsv(..., escape: '')` | El escape por default de PHP (backslash) no es el estándar CSV y rompe nombres de cliente que terminan en `\`; además genera deprecation warning en PHP 8.4 |
| CSV/PDF de Reportes usan `$customer->name ?? 'Sin cliente'` sin `?->` | Verificado que no genera warning de PHP (`??` evalúa su operando izquierdo en modo isset, suprime el warning en toda la cadena); PHPStan señala correctamente que `?->` sería redundante ahí |
| PDF de Reportes no reusa `pdf.partials.brand` | Ese partial junta el logo INNOVA con branding de Interceramic/Representante pensado para documentos que sí ve el cliente; el reporte es interno, así que construye su propio bloque de solo-logo sin modificar el partial compartido |
| Descarga de PDF/CSV de Reportes nunca se deshabilita por falta de datos | Un reporte "sin comprobantes" también es un resultado válido (constancia de que un mes no tuvo movimiento) |
| Reportes expone mes calendario y rango personalizado, no solo mes | El backend ya aceptaba cualquier rango desde el Paso 1; se decidió exponerlo de una vez en el frontend en vez de dejarlo como capacidad latente |
| `ReportsView.vue` es directamente el Reporte de Comprobantes, sin una pantalla "índice" de reportes | Solo existe un reporte por ahora — una estructura de hub para reportes futuros sería especulativa; se construye cuando se confirme un segundo reporte real |
| Cédula de Identificación Fiscal del PDF como imagen fija, no como texto leído de `business_settings` | El RFC/idCIF/nombre/QR reales ya vienen "horneados" en el recorte oficial de la Constancia de Situación Fiscal del cliente; mantenerlo como texto condicional dejó de tener sentido una vez que existe el archivo real — mismo criterio que "*REPRESENTANTE" (fijo en la plantilla, no en Configuración, porque no cambia) |
| Dashboard "productos más vendidos" se reescribió sobre cotizaciones pagadas en vez de borrarse al quitar Ventas | El endpoint y su formato de respuesta ya no dependían de nada exclusivo de Venta; mantenerlo evita perder una vista útil del negocio solo porque cambió su fuente de datos |

Historial completo de decisiones en `decisions-and-learnings.md`.

## Pendientes

**No bloqueantes, por módulo:**

- **Inventario**: importador de listas de precios de proveedores; borrado de producto/medida completos solo aplica al Catálogo de pisos, no a "Otros productos".
- **Cotizaciones**: URLs `/cotizaciones/...` y algunos mensajes 422 del backend siguen diciendo "cotización" tras el renombre visible a "Presupuestos"; verificar en navegador que tras la fusión del 22-oct no quedó la tabla de líneas duplicada en ningún detalle; probar en el navegador los dos cambios del 23-oct (borrado de Presupuesto, y el nuevo estado `Pagada` del catálogo) y hacer los commits correspondientes (backend y frontend) una vez confirmados.
- **Comprobante Fiscal**: `app/Http/Controllers/Comprobante/ComprobanteController.php` falla `pint --test` (formato) — preexistente, detectado el 23-oct-2026 al construir Reportes (Paso 1) sin tocar ese archivo; no corregido de pasada, queda como paso aparte nombrado. `business_regime` (Régimen de Actividad Empresarial) sigue sin dato real capturado, aunque no se imprime en el PDF — no bloqueante.
- **Reportes**:
  - Verificar visualmente un PDF de 2-3 hojas (~70-140 comprobantes) una vez exista un mes real con ese volumen, o generando una prueba manual — las capacidades de `PdfSheetLayout` se midieron por conteo de páginas, no visualmente, por falta de rasterizador en el entorno de construcción.
  - Mergear `feat/reports` a `develop` — se decidió deliberadamente construir el frontend completo (incluido el rango personalizado) antes del merge. La entrada de sidebar de Reportes mencionaba estar "entre Comprobantes y Punto de venta" — confirmar su posición ahora que Punto de venta ya no existe.
  - **Decidir si se agrega algún reporte nuevo al módulo** (sugerido 2026-10-23, sin decidir): Presupuestos convertidos vs. no convertidos, Inventario con bajo stock/rotación, Top clientes o productos. (El reporte de "Ventas por periodo" sugerido originalmente ya no aplica.) Ver `claude/PLAN-REPORTES.md` §13.
  - El botón "Volver" al abrir un Comprobante desde Reportes regresa a `comprobantes.index`, no a `/reportes` — comportamiento existente del detalle, aceptado tal cual; cambiarlo sería un ajuste aparte en `ComprobanteDetailView.vue`, no pedido.
- **Clientes**: "Nueva cotización" desde el detalle del cliente con el cliente ya elegido. **Nuevo (26-oct-2026)**: el detalle de Cliente no muestra un historial de Comprobantes (nunca lo tuvo — solo mostraba Presupuestos y, hasta su eliminación, Ventas); sin decidir si se construye.
- **Autenticación**: "Recordar sesión" y restablecer contraseña (sin endpoint detrás todavía).
- **Transversal**: título de pestaña genérico en vistas de detalle; `site.webmanifest` faltante.

**Antes de desplegar a producción:** confirmar que el despliegue vigente ya corresponde al `main` fusionado el 22-oct, mergear `feat/reports` cuando esté listo, y desplegar la eliminación del módulo de Ventas (26-oct-2026, incluye migración que dropea tablas — correr con cuidado y backup previo, aunque confirmado sin datos reales que perder).

## Roadmap

1. ~~Sistema de diseño~~ ✅
2. ~~Módulo de Inventario~~ ✅
3. ~~Módulo de Cotizaciones~~ ✅ (incluido Comprobante Fiscal Simplificado)
4. ~~Módulo de Punto de Venta~~ ✅ — **eliminado 26-oct-2026**: el cliente confirmó que nunca lo usará, el sistema se queda solo con Presupuestos y Comprobantes (ver Hitos recientes)
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
fix(quotes): :bug: reject converting or confirming a cancelled quote
style(quotes): :lipstick: rename quotes module to presupuestos in user-facing text
refactor(inventory): :recycle: split ProductFormView into section components and productForm helpers
config(app): :wrench: switch application timezone to America/Mexico_City
hotfix(auth): :ambulance: fix logout using wrong guard
test(quotes): :white_check_mark: add end-to-end test for the quote payment flow
docs: :memo: document piece-based sales and component split rule
```

Historial completo de commits en `pavh-backend`/`pavh-frontend` — este documento ya no reproduce la lista completa para evitar que se desactualice.
