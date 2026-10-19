# Guía técnica — Módulo Presupuesto → Comprobante Fiscal Simplificado

Referencia técnica de este módulo para cualquier programador o IA que lo retome: qué hace, cómo está modelado, qué endpoints existen, qué reglas de negocio aplican y por qué. Se actualiza al cierre de cada paso de construcción. Complementa a `PROJECT.md`/`AGENT.md` (convenciones generales del proyecto) y a `claude/PLAN-COMPROBANTE-FISCAL.md` (historial de decisiones de la sesión de diseño, con más contexto narrativo que este documento).

**Estado al 2026-10-19**: backend de Presupuesto/Comprobante completo (Pasos 1-3) y probado. Configuración de datos fijos del negocio también lista (Paso 4a). Falta: plantilla PDF del Comprobante, y todo el frontend.

---

## 1. Qué resuelve este módulo

El cliente (Pisos y Acabados Villa Hidalgo / INNOVA, sub-distribuidor autorizado de Interceramic) no quiere cotizaciones/ventas simples. Su flujo real:

1. Se cotiza un **Presupuesto** (extiende la entidad `Quote` ya existente).
2. El cliente paga de inmediato o se le da crédito. Cuando se confirma el pago (sea cual sea el momento), el vendedor lo marca explícitamente — **esto es lo que descuenta stock**, no la creación del Presupuesto ni la generación del Comprobante.
3. Al cierre de mes, de los Presupuestos ya pagados, el vendedor elige cuáles convertir en **Comprobante Fiscal Simplificado** (entidad nueva `Comprobante`) — un documento de respaldo interno del sub-distribuidor (NO es un CFDI real ante el SAT), con folio propio y una fecha que se puede ajustar libremente incluso después de creado.

Reemplaza, para este flujo, el rol que hoy cumple la conversión Cotización→Venta (POS) — que se mantiene como módulo aparte, de baja prioridad, sin tocar en este trabajo.

## 2. Modelo de datos

### `quotes` (Presupuesto) — columnas agregadas

| Columna | Tipo | Notas |
| --- | --- | --- |
| `discount` | decimal(10,2) nullable | Descuento. |
| `down_payment` | decimal(10,2) nullable | Anticipo. |
| `shipping_cost` | decimal(10,2) nullable | Costo de envío. |
| `payment_terms` | string(20), default `'cash'` | `'cash'` \| `'credit'` (`Rule::in(Quote::PAYMENT_TERMS)`). |
| `credit_due_date` | date nullable | Solo tiene sentido con `payment_terms = 'credit'`. Se limpia a null al pasar a `'cash'`, tanto en `store` como en `update`. |
| `paid_at` | timestamp nullable | Se llena solo vía `PATCH /quotes/{id}/mark-paid`. |
| `paid_by` | FK nullable a `users` | Igual patrón que `created_by`. |

`total` ya no es siempre igual a `subtotal`: ahora `total = subtotal - discount`. `subtotal` sigue siendo la suma de líneas, sin cambios.

`Quote::balance()`: `total + shipping_cost - down_payment`, tratando nulls como 0. **Puede dar negativo a propósito** (un anticipo mayor al total/envío no se rechaza — el negocio le queda debiendo al cliente). No se persiste, se calcula al vuelo y se expone en `QuoteResource`.

### `customers` — columna agregada

`postal_code` (string(10) nullable) — `address`/`neighborhood`/`city` ya existían de una migración anterior no relacionada a este trabajo (2026-10-16).

### `comprobantes` (nueva, Comprobante Fiscal Simplificado)

| Columna | Notas |
| --- | --- |
| `folio` | Server-side, formato `CF-0001` (`ComprobanteFolioGenerator`, `withTrashed()`). |
| `quote_id` | FK + **UNIQUE** — refuerza la relación 1 a 1 con `Quote`. Un Comprobante dado de baja sigue ocupando el `quote_id` (el UNIQUE lo incluye), así que tampoco se puede generar otro para esa cotización. |
| `customer_id` | nullable (mismo criterio que `quotes`/`sales`). |
| `date` | La única columna editable después de creado, sin límite, vía `PATCH /comprobantes/{id}`. |
| `subtotal`, `total` | Copiados tal cual del Presupuesto al convertir — **no se recalculan**, son una foto del momento. |
| `total_in_words` | Calculado al crear, con `App\Services\AmountInWords::pesos()` (servicio propio, sin librería externa — maneja "un/veintiún" antes de "mil"/"pesos", "cien/ciento", y "un millón de pesos" con el "de" solo en millones exactos). |
| `created_by`, SoftDeletes | Igual que `quotes`/`sales`. |

**Decisión de diseño explícita**: `comprobantes` NO duplica `discount`/`down_payment`/`shipping_cost`/`payment_terms` de `quotes`. El Comprobante real (visto en la plantilla física de Interceramic) no muestra ese desglose — solo Total y Total con letra, porque para cuando se genera, el Presupuesto ya está pagado al 100%. Si algún día hiciera falta ver ese desglose histórico, se consulta vía `Comprobante::quote` (la relación sigue viva mientras el Presupuesto no se borre).

### `comprobante_items` (nueva)

Misma forma que `quote_items`. Se llenan copiando `quote_items` tal cual en `POST /comprobantes` — **no se reciben en el payload**, a diferencia de Cotización→Venta (que sí permite ajustar cantidades/precios al convertir). Aquí no hay ningún caso de uso para re-editar al convertir: es solo "ya se pagó, generar el recibo tal cual se cotizó".

### `stock_movements` — columna agregada

`quote_id` (nullable) + parámetro opcional `?Quote $quote` en `StockMovement::record()`. Permite que un movimiento con `source: 'quote_payment'` diga de qué Presupuesto vino, igual que `sale_id` en los movimientos de venta. **Todavía no expuesto en `StockMovementResource`** (pendiente, no bloqueante).

### `business_settings` (nueva, Configuración — registro único)

Datos fijos del negocio que aparecen en el Comprobante y no cambian por documento: Lugar de expedición, Régimen de Actividad Empresarial, y los datos de la Cédula de Identificación Fiscal (RFC e idCIF).

| Columna | Tipo | Notas |
| --- | --- | --- |
| `place_of_issue` | string nullable, max 255 | Lugar de expedición. |
| `business_regime` | string nullable, max 255 | Régimen de Actividad Empresarial. |
| `tax_id` | string nullable, max 255 | RFC. |
| `tax_id_number` | string nullable, max 255 | idCIF (Cédula de Identificación Fiscal). |

Es una tabla singleton, no un catálogo: siempre hay exactamente un registro (id 1).

- `BusinessSetting::current()`: lee el id 1; si no existe lo crea vacío con `insertOrIgnore` (evita condición de carrera entre dos primeras lecturas simultáneas).
- Cualquier alta se fuerza al id 1 (un segundo `create()` falla por llave primaria duplicada) y el evento `deleting` siempre se cancela (`delete()` devuelve `false`) — no hay manera de tener un segundo registro ni de borrarlo.
- Un campo que no viene en el `PUT` se queda como estaba; para vaciarlo hay que mandarlo explícitamente como `null`.

**Pendiente, deliberadamente fuera de alcance por ahora**: el archivo/imagen del QR de la Cédula de Identificación Fiscal — no se agregó manejo de archivo todavía porque no se tiene el archivo real; se agregará como un campo aparte (probablemente de imagen) cuando esté disponible.

## 3. Endpoints

| Método y ruta | Qué hace | Guards |
| --- | --- | --- |
| `PATCH /quotes/{quote}/mark-paid` | Confirma el pago de un Presupuesto. Descuenta stock (agregado por producto, `hasSufficientStock()` antes de mutar, `StockMovement::record(source: 'quote_payment', quote: $quote)`), guarda `paid_at`/`paid_by`. Bloquea la cotización y sus productos en orden de id (evita doble pago concurrente). | `Quote::markPaidBlockedMessage()`: rechaza si no está en Borrador o si ya tiene `paid_at`. También rechaza si una línea referencia un producto dado de baja, o una variante sin `boxes` capturado (dato viejo anterior a la venta por caja). |
| `GET /quotes/{id}/to-comprobante` | Solo lectura, prellena cliente/fecha de hoy/líneas/subtotal/total/total_in_words para que el frontend lo muestre antes de confirmar. Mismo espíritu que `GET /quotes/{id}/convert` (ya existente para Venta). | `Quote::comprobanteConversionBlockedMessage()`: rechaza si `paid_at` es null, o si ya tiene Comprobante. |
| `POST /comprobantes` | Recibe `quote_id` + `date` opcional (default `now()`). Dentro de transacción: bloquea la cotización, vuelve a revisar el guard (no confía solo en el `GET`), copia líneas/montos, genera folio, calcula `total_in_words`. | Mismo guard que el `GET`, revalidado server-side. |
| `PATCH /comprobantes/{id}` | Único campo editable: `date`. Sin ningún guard de "ya no se puede editar" — es deliberadamente editable sin límite. | `UpdateComprobanteDateRequest`, solo acepta `date`. |
| `DELETE /quotes/{id}` | (Ya existía, ahora con guard agregado) | Rechaza 422 "Esta cotización ya fue pagada y no se puede eliminar." si `paid_at` no es null. |
| `GET /api/business-settings` | Devuelve el registro actual (siempre existe gracias a `current()`). | — |
| `PUT /api/business-settings` | Actualiza los 4 campos de Configuración. | `UpdateBusinessSettingRequest`: los 4 campos `nullable\|string\|max:255`. Sin ruta de alta ni de borrado (`POST`/`DELETE` responden 405). |

Efecto secundario de marcar como pagada: `Quote::conversionBlockedMessage()` (conversión a Venta), el guard de cancelación, y el guard de `update` ahora también rechazan una cotización con `paid_at` — evita descuadrar el stock (pagarla y luego convertirla a Venta, o editarla/cancelarla, dejaría el stock mal contado).

## 4. Reglas de negocio (resumen)

- Relación Presupuesto↔Comprobante: **1 a 1**, forzada por el UNIQUE en `comprobante.quote_id`.
- El stock se descuenta **al marcar como pagado**, nunca al generar el Comprobante ni al crear/editar el Presupuesto.
- "Marcar como pagado" es un paso de negocio propio, independiente en el tiempo de "generar Comprobante" (pueden pasar semanas entre uno y otro — el pago ocurre cuando el cliente paga, el Comprobante hasta el cierre de mes).
- El plazo de crédito es variable (`credit_due_date` capturado a mano, no un cálculo de N días fijos).
- `Saldo` puede ser negativo (anticipo mayor a lo debido) — no se valida ni rechaza, es un estado válido.
- `Total = Subtotal − Descuento` sí se valida: un descuento que dejara `Total` negativo se rechaza con 422 antes de escribir nada.
- Venta (POS) sigue existiendo tal cual, sin tocar — es una tercera vía, no la prioridad de este trabajo.
- No es un CFDI real — no hay conexión con Factura Digital (factdigital.com) en este módulo.
- Los datos fijos del negocio (Configuración) son un registro único, editable desde cualquier parte del sistema, no capturados por documento.

## 5. Decisiones y su razón

| Decisión | Razón |
| --- | --- |
| `comprobantes.quote_id` UNIQUE en vez de una tabla puente N:N | Confirmado con el cliente: cada Presupuesto pagado genera su propio Comprobante individual (1 a 1); lo que se "junta" al cierre de mes es un resumen externo (folio + total), no un documento nuevo en el sistema. |
| `comprobantes` no copia discount/down_payment/shipping_cost/payment_terms | El Comprobante real no los muestra (solo Total y Total con letra); copiarlos sería dato muerto. |
| Stock se descuenta en `mark-paid`, no en la conversión a Comprobante | El pago es el evento de negocio real que compromete la mercancía; el Comprobante es solo un trámite contable posterior, a veces mucho después. |
| `paid_at`/`paid_by` en vez de reusar el `status` (quote_statuses) existente | `status` (Borrador/Convertida/Cancelada) ya tiene un significado específico ligado a la conversión a Venta; mezclarlo con "pagado" habría sido ambiguo. Son dos conceptos independientes que pueden convivir (una cotización puede estar "Borrador" y "pagada" a la vez). |
| `Saldo` negativo permitido, sin validar | Decisión de negocio explícita: un anticipo mayor al total es un caso real (el negocio le queda debiendo al cliente), no un error de captura. |
| `PATCH /comprobantes/{id}` solo acepta `date`, sin guard de inmutabilidad | Decisión de negocio explícita: la fecha debe poder ajustarse sin límite para cuadrar el cierre de mes, a diferencia del resto de documentos del sistema (Cotización/Venta), que se vuelven inmutables al concretarse. |
| `AmountInWords::pesos()` propio, sin librería externa | No existía ninguna utilidad de número a letras en el proyecto; el caso (pesos mexicanos con centavos) es acotado y no justifica una dependencia nueva. |
| Líneas de `comprobante_items` copiadas server-side, no recibidas en el payload | A diferencia de Cotización→Venta, aquí no hay ningún requisito de re-editar cantidades/precios al convertir — es solo generar el recibo de lo ya pagado. |
| `business_settings` como tabla singleton (forzada a id 1) en vez de un `config`/`.env` | Son datos que el cliente puede necesitar corregir él mismo desde el sistema (cambio de régimen, por ejemplo), no constantes de despliegue; una tabla con endpoints permite eso sin tocar código. |

## 6. Pendientes conocidos

- `StockMovementResource` no expone todavía `quote_id`.
- Configuración: el archivo/imagen del QR de la Cédula de Identificación Fiscal — sin construir. Pendiente que Justin comparta el archivo real.
- Plantilla PDF del Comprobante — sin construir (incluye el aviso legal fijo, transcrito en `claude/PLAN-COMPROBANTE-FISCAL.md`).
- Todo el frontend: formulario de Presupuesto con los campos nuevos, acción "Marcar como pagado", pantalla de conversión a Comprobante, listado/detalle de Comprobantes, pantalla de Configuración.

## 7. Historial de pasos de construcción

| Paso | Fecha | Contenido |
| --- | --- | --- |
| 1 | 2026-10-19 | Esquema base: columnas nuevas en `quotes`/`customers`, tabla `comprobantes`/`comprobante_items`, modelos, `ComprobanteFolioGenerator`. Sin endpoints de negocio todavía. 299→311 tests. |
| 2 | 2026-10-19 | "Marcar como pagado": `paid_at`/`paid_by`, `PATCH /quotes/{id}/mark-paid`, descuento de stock, `Quote::balance()`, recálculo de `total`, bloqueo de edición/cancelación/conversión-a-venta de una cotización pagada. 311→336 tests. |
| 3 | 2026-10-19 | Conversión a Comprobante: `GET /quotes/{id}/to-comprobante`, `POST /comprobantes`, `PATCH /comprobantes/{id}` (fecha), `AmountInWords::pesos()`. Más dos ajustes: `DELETE /quotes/{id}` bloqueado si pagada, `credit_due_date` se limpia en `update` al pasar a `cash`. 336 tests. |
| 4a | 2026-10-19 | Mismo ajuste de `credit_due_date` aplicado también en `store`. Configuración: tabla/modelo `business_settings` (registro único), `GET`/`PUT /api/business-settings`, sin manejo de archivo del QR todavía. 336→342 tests. |
