# Guía técnica — Módulo Presupuesto → Comprobante Fiscal Simplificado

Referencia técnica de este módulo para cualquier programador o IA que lo retome: qué hace, cómo está modelado, qué endpoints existen, qué reglas de negocio aplican y por qué. Se actualiza al cierre de cada paso de construcción. Complementa a `PROJECT.md`/`AGENT.md` (convenciones generales del proyecto) y a `claude/PLAN-COMPROBANTE-FISCAL.md` (historial de decisiones de la sesión de diseño, con más contexto narrativo que este documento).

**Estado al 2026-10-19**: backend de Presupuesto/Comprobante/Configuración completo y probado, incluida la plantilla PDF del Comprobante. Falta: datos reales de la Cédula de Identificación Fiscal (RFC/idCIF/QR), el rediseño del PDF de Presupuesto/Cotización (para que haga juego con el nuevo Comprobante), y todo el frontend.

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
| `total_in_words` | Calculado al crear, con `App\Services\AmountInWords::pesos()` (servicio propio, sin librería externa — maneja "un/veintiún" antes de "mil"/"pesos", "cien/ciento", y "un millón de pesos" con el "de" solo en millones exactos; probado hasta $99,999,999.99). |
| `created_by`, SoftDeletes | Igual que `quotes`/`sales`. |

**Decisión de diseño explícita**: `comprobantes` NO duplica `discount`/`down_payment`/`shipping_cost`/`payment_terms` de `quotes`. El Comprobante real (visto en la plantilla física de Interceramic) no muestra ese desglose — solo Total y Total con letra, porque para cuando se genera, el Presupuesto ya está pagado al 100%. Si algún día hiciera falta ver ese desglose histórico, se consulta vía `Comprobante::quote` (la relación sigue viva mientras el Presupuesto no se borre).

### `comprobante_items` (nueva)

Misma forma que `quote_items`. Se llenan copiando `quote_items` tal cual en `POST /comprobantes` — **no se reciben en el payload** de creación. No hay ningún caso de uso para re-editar al convertir: es solo "ya se pagó, generar el recibo tal cual se cotizó".

### `stock_movements` — columna agregada

`quote_id` (nullable) + parámetro opcional `?Quote $quote` en `StockMovement::record()`. **Todavía no expuesto en `StockMovementResource`** (pendiente, no bloqueante).

### `business_settings` (nueva, Configuración — registro único)

Datos fijos del negocio, editables desde el sistema (`GET`/`PUT /api/business-settings`), sin necesitar un deploy para cambiarlos.

| Columna | Tipo | Notas |
| --- | --- | --- |
| `place_of_issue` | string nullable, max 255 | Lugar de expedición. Se usa en el PDF. |
| `business_regime` | string nullable, max 255 | Existe en el modelo, pero **ya no se imprime en el PDF** (ver §3 y Decisiones — en el papel real esa línea es solo una etiqueta, nunca se llena). |
| `tax_id` | string nullable, max 255 | RFC. Se usa en el PDF (caja de Cédula Fiscal) cuando tiene valor. |
| `tax_id_number` | string nullable, max 255 | idCIF. Igual que `tax_id`. |
| `address` | string nullable, max 255 | Se usa en el PDF. |
| `neighborhood` | string nullable, max 255 | Se usa en el PDF. |
| `city` | string nullable, max 255 | Se usa en el PDF. |
| `postal_code` | string nullable, max 255 | Se usa en el PDF. |
| `phone_primary` | string nullable, max 255 | Se usa en el PDF. |
| `phone_secondary` | string nullable, max 255 | Se usa en el PDF. |
| `email` | string nullable, max 255 | Se usa en el PDF. Sin validación de formato de correo, mismo criterio que los demás campos. |

Es una tabla singleton, no un catálogo: siempre hay exactamente un registro (id 1).

- `BusinessSetting::current()`: lee el id 1; si no existe lo crea vacío con `insertOrIgnore` (evita condición de carrera entre dos primeras lecturas simultáneas).
- Cualquier alta se fuerza al id 1 (un segundo `create()` falla por llave primaria duplicada) y el evento `deleting` siempre se cancela (`delete()` devuelve `false`) — no hay manera de tener un segundo registro ni de borrarlo.
- Un campo que no viene en el `PUT` se queda como estaba; para vaciarlo hay que mandarlo explícitamente como `null`.

**Datos reales ya cargados** (vía `PUT /api/business-settings`, sin necesitar frontend): `address`, `neighborhood`, `city`, `postal_code`, `phone_primary`, `phone_secondary`, `email` — Justin los proporcionó y se cargaron directo por API.

**Pendiente**: `place_of_issue`, `business_regime` (su texto real, aunque ya no se imprima — útil tenerlo igual), `tax_id`, `tax_id_number`, y el archivo/imagen del QR de la Cédula de Identificación Fiscal (este último no tiene columna todavía — se agregará como campo de imagen aparte cuando exista el archivo real).

## 3. Endpoints

| Método y ruta | Qué hace | Guards |
| --- | --- | --- |
| `PATCH /quotes/{quote}/mark-paid` | Confirma el pago de un Presupuesto. Descuenta stock (agregado por producto, `hasSufficientStock()` antes de mutar, `StockMovement::record(source: 'quote_payment', quote: $quote)`), guarda `paid_at`/`paid_by`. Bloquea la cotización y sus productos en orden de id (evita doble pago concurrente). | `Quote::markPaidBlockedMessage()`: rechaza si no está en Borrador o si ya tiene `paid_at`. También rechaza si una línea referencia un producto dado de baja, o una variante sin `boxes` capturado. |
| `GET /quotes/{id}/to-comprobante` | Solo lectura, prellena cliente/fecha de hoy/líneas/subtotal/total/total_in_words. | `Quote::comprobanteConversionBlockedMessage()`: rechaza si `paid_at` es null, o si ya tiene Comprobante. |
| `POST /comprobantes` | Recibe `quote_id` + `date` opcional (default `now()`). Dentro de transacción: bloquea la cotización, revalida el guard, copia líneas/montos, genera folio, calcula `total_in_words`. | Mismo guard que el `GET`, revalidado server-side. |
| `PATCH /comprobantes/{id}` | Único campo editable: `date`. Sin ningún guard de inmutabilidad — deliberadamente editable sin límite. | `UpdateComprobanteDateRequest`, solo acepta `date`. |
| `DELETE /quotes/{id}` | (Ya existía, ahora con guard agregado) | Rechaza 422 "Esta cotización ya fue pagada y no se puede eliminar." si `paid_at` no es null. |
| `GET /api/business-settings` | Devuelve el registro actual (siempre existe gracias a `current()`). | — |
| `PUT /api/business-settings` | Actualiza los campos de Configuración. | `UpdateBusinessSettingRequest`: todos `nullable\|string\|max:255`. Sin ruta de alta ni de borrado (`POST`/`DELETE` responden 405). |
| `GET /comprobantes/{id}/pdf` | Genera y descarga el PDF del Comprobante (ver §4 para el detalle de la plantilla). Nunca se bloquea por datos faltantes en Configuración o por falta de cliente — siempre responde 200 si el Comprobante existe. | — |

Efecto secundario de marcar como pagada: `Quote::conversionBlockedMessage()` (conversión a Venta), el guard de cancelación, y el guard de `update` ahora también rechazan una cotización con `paid_at`.

## 4. La plantilla PDF del Comprobante

`App\Services\ComprobantePdfGenerator` + vista `resources/views/pdf/comprobante.blade.php`, usando dompdf con el mismo patrón/fuentes que ya usaba el PDF de Cotización/Venta (**ese PDF existente NO se tocó** — sigue igual que antes; su rediseño queda pendiente, ver §6).

- **Tamaño de página**: media carta horizontal (612 × 396 pt / 5.5 × 8.5 in), confirmado contra el papel real del talonario.
- **Encabezado**: logo de **INNOVA** en escala de grises (`resources/images/branding/`, decisión consciente de usar la marca propia del cliente en vez de reproducir el logo de Interceramic del papel físico), "SUB-DISTRIBUIDOR AUTORIZADO" y "*REPRESENTANTE: Pedro López Laris" — ambos **fijos en la plantilla**, no vienen de Configuración (el representante no cambia). Al centro, título + dirección/contacto de `business_settings` (cada línea solo si tiene valor). A la derecha, caja con FOLIO, Lugar de expedición, Fecha, y la etiqueta "RÉGIMEN DE ACTIVIDAD EMPRESARIAL" — **esta última sin ningún valor impreso debajo**, igual que en el papel real donde esa línea nunca se llena.
- **Caja de Cédula de Identificación Fiscal**: ~27% del ancho × ~22% del alto de página, esquina inferior izquierda. Muestra RFC e idCIF de `business_settings` solo cuando tienen valor; si no, queda vacía (sin inventar contenido ni placeholder de texto).
- **Tabla de líneas**: CANT. (con el mismo criterio que la nota de venta — cajas+m² para piso, unidades para productos simples), DESCRIPCIÓN, VALOR UNITARIO, IMPORTE.
- **Auto-ajuste por cantidad de líneas**: con 2-6 líneas, tamaño normal. De 7 a ~12 líneas, reduce progresivamente fuente/alto de fila (con un piso mínimo legible) para seguir cabiendo en una sola hoja. Más allá de ese máximo, pasa a una segunda hoja con un encabezado compacto repetido (folio/fecha/cliente/"Hoja X de Y") — el pie (Cédula, Total con letra, Total, aviso legal) solo se imprime en la última hoja, nunca partido entre hojas.
- **Pie**: "TOTAL CON LETRA" y "TOTAL" (probado hasta $99,999,999.99 sin desbordar su caja), y el aviso legal fijo, texto verbatim:

  > EL PRESENTE COMPROBANTE ACREDITA LA LEGITIMA PROPIEDAD DE LOS BIENES DE QUIEN LOS POSEÉ Y/O LA OPERACIÓN COMERCIAL REALIZADA. PARA SU EXPEDICIÓN SE CUMPLE CON LO PREVISTO EN LA REGLA DE CARÁCTER GENERAL I.2.7.1.22. DE LA RESOLUCIÓN MISCELANEA PARA EL 2014 CON RELACIÓN A LO ESTABLECIDO EN EL ARTÍCULO 29-A DEL CÓDIGO FISCAL DE LA FEDERACIÓN PARA LOS COMPROBANTES FISCALES. PARA PODER DEDUCIRSE O ACREDITARSE FISCALMENTE LAS CANTIDADES AMPARADAS EN ESTE COMPROBANTE SIMPLIFICADO DEBERÁ SOLICITAR OPORTUNAMENTE SE LE RESTITUYA POR UN COMPROBANTE FISCAL DIGITAL POR INTERNET DENTRO DEL PLAZO QUE ESTIPULE EL PROVEEDOR.

Tests de feature cubren: 200 + content-type PDF con Configuración vacía, 200 sin cliente, y que el folio/aviso legal/datos de Configuración/RFC/idCIF/total con letra/importes aparecen en el HTML de la plantilla (la revisión se hace sobre el HTML, no sobre el PDF binario, porque dompdf comprime el texto).

## 5. Reglas de negocio (resumen)

- Relación Presupuesto↔Comprobante: **1 a 1**, forzada por el UNIQUE en `comprobante.quote_id`.
- El stock se descuenta **al marcar como pagado**, nunca al generar el Comprobante ni al crear/editar el Presupuesto.
- "Marcar como pagado" es un paso de negocio propio, independiente en el tiempo de "generar Comprobante".
- El plazo de crédito es variable (`credit_due_date` capturado a mano).
- `Saldo` puede ser negativo — no se valida ni rechaza.
- `Total = Subtotal − Descuento` sí se valida: un descuento que dejara `Total` negativo se rechaza con 422.
- Venta (POS) sigue existiendo tal cual, sin tocar.
- No es un CFDI real — no hay conexión con Factura Digital (factdigital.com) en este módulo.
- Los datos fijos del negocio (Configuración) son un registro único, editable desde el sistema (ya sea por API directa o, más adelante, por su propia pantalla).

## 6. Decisiones y su razón

| Decisión | Razón |
| --- | --- |
| `comprobantes.quote_id` UNIQUE en vez de una tabla puente N:N | Confirmado con el cliente: 1 a 1; lo que se "junta" al cierre de mes es un resumen externo, no un documento nuevo en el sistema. |
| `comprobantes` no copia discount/down_payment/shipping_cost/payment_terms | El Comprobante real no los muestra; copiarlos sería dato muerto. |
| Stock se descuenta en `mark-paid`, no en la conversión a Comprobante | El pago es el evento de negocio real que compromete la mercancía. |
| `paid_at`/`paid_by` en vez de reusar `status` | Son dos conceptos independientes que pueden convivir. |
| `Saldo` negativo permitido, sin validar | Un anticipo mayor al total es un caso real de negocio. |
| `PATCH /comprobantes/{id}` solo acepta `date`, sin guard de inmutabilidad | La fecha debe poder ajustarse sin límite para cuadrar el cierre de mes. |
| `AmountInWords::pesos()` propio, sin librería externa | Caso acotado, no justifica una dependencia nueva. |
| Líneas de `comprobante_items` copiadas server-side | No hay requisito de re-editar al convertir. |
| `business_settings` como tabla singleton en vez de `config`/`.env` | El cliente puede necesitar corregir estos datos él mismo desde el sistema. |
| Logo de INNOVA en vez del logo de Interceramic en el PDF | Decisión consciente de usar la marca propia del negocio en el documento generado por el sistema, en vez de reproducir el logo de un tercero (Interceramic) tal cual aparece en el papel físico original. |
| "*REPRESENTANTE" fijo en la plantilla, no en Configuración | Confirmado: el representante (Pedro López Laris) no cambia; no amerita ser un campo editable. |
| "RÉGIMEN DE ACTIVIDAD EMPRESARIAL" se imprime solo como etiqueta, sin valor | En el papel físico real esa línea nunca se llena a mano — es solo el encabezado de la casilla. El campo `business_regime` se queda en el modelo por si se necesita después, pero no se imprime. |
| Auto-ajuste de fuente hasta ~12 líneas, luego segunda hoja con encabezado repetido | 6 líneas resultó insuficiente para el caso común (una compra de piso casi siempre lleva piso + pegazulejo + boquilla + accesorios); seguir reduciendo la fuente sin límite compromete legibilidad e impresión. |

## 7. Pendientes conocidos

- `StockMovementResource` no expone todavía `quote_id`.
- Datos reales pendientes en `business_settings`: `place_of_issue`, `business_regime` (su texto, aunque no se imprima), `tax_id`, `tax_id_number`, y el archivo/imagen del QR de la Cédula Fiscal (sin columna todavía).
- **Rediseño del PDF de Presupuesto/Cotización**: el PDF existente de Cotización/Venta no se tocó en este trabajo — sigue con su diseño anterior. Ahora que el Comprobante tiene una plantilla nueva (logo INNOVA, media carta horizontal, etc.), queda pendiente decidir si el PDF de Presupuesto se rediseña para hacer juego, y resolverlo como un paso aparte.
- Todo el frontend: formulario de Presupuesto con los campos nuevos, acción "Marcar como pagado", pantalla de conversión a Comprobante, listado/detalle de Comprobantes, pantalla de Configuración (aunque los datos ya se pueden cargar por API mientras tanto).

## 8. Historial de pasos de construcción

| Paso | Fecha | Contenido |
| --- | --- | --- |
| 1 | 2026-10-19 | Esquema base: columnas nuevas en `quotes`/`customers`, tabla `comprobantes`/`comprobante_items`, modelos, `ComprobanteFolioGenerator`. 299→311 tests. |
| 2 | 2026-10-19 | "Marcar como pagado": `paid_at`/`paid_by`, `PATCH /quotes/{id}/mark-paid`, descuento de stock, `Quote::balance()`, recálculo de `total`, bloqueos de edición/cancelación/conversión-a-venta. 311→336 tests. |
| 3 | 2026-10-19 | Conversión a Comprobante: `GET /quotes/{id}/to-comprobante`, `POST /comprobantes`, `PATCH /comprobantes/{id}` (fecha), `AmountInWords::pesos()`. 336 tests. |
| 4a | 2026-10-19 | `credit_due_date` también limpiado en `store`. Configuración: `business_settings` (registro único), `GET`/`PUT /api/business-settings`. 336→342 tests. |
| 4b | 2026-10-19 | Esta guía técnica copiada a la raíz del repo (sin cambios de código). |
| 5 | 2026-10-19 | Primera versión de la plantilla PDF del Comprobante (`GET /comprobantes/{id}/pdf`), `business_settings` ampliado con 7 campos de dirección/contacto. Página en carta vertical (provisional), espacios reservados para logo y Cédula Fiscal. 342→346 tests. |
| 5b | 2026-10-19 | Página corregida a media carta horizontal (tamaño real del talonario), proporciones de la caja de Cédula Fiscal reescaladas, logo real de INNOVA insertado, "*REPRESENTANTE" fijado a "Pedro López Laris". Ajuste puramente visual, sin cambios a los tests. |
| 5c/5d | 2026-10-19 | Corregido el desbordamiento del monto en la caja de TOTAL. Auto-ajuste de fuente/alto de fila hasta ~12 líneas de producto, y segunda hoja con encabezado compacto repetido (folio/fecha/cliente/"Hoja X de Y") cuando se rebasa ese máximo — probado hasta con un total de $99,999,999.99. |
| 5e | 2026-10-19 | "RÉGIMEN DE ACTIVIDAD EMPRESARIAL" se deja solo como etiqueta fija, sin imprimir ningún valor debajo (así es en el papel físico real). |
