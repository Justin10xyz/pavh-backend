# AGENT.md — Reglas para IA trabajando en PAVH

Este documento es para cualquier agente (Claude Code, Cursor, etc.) que trabaje en `pavh-backend` o `pavh-frontend`. Léelo antes de generar código. El objetivo es que cualquier sesión nueva produzca resultados consistentes con las anteriores.

## Reglas generales de trabajo

- **Pedir pasos acotados, no tareas abiertas.** Justin prefiere prompts como "crea la instancia de axios" en vez de "conecta todo el frontend con el backend". Si te piden una tarea grande, divídela en pasos nombrados y secuenciales antes de escribir código. Si un paso resulta muy grande, divídelo también (ej. crear vs. editar en pasos separados).
- **Backend antes que frontend** cuando haya dependencia entre ambos — evita debug en capas cruzadas.
- **Empezar por lo visible.** Orden de construcción de frontend: UI visible → router → capa de config/servicios → estado (Pinia) → guards.
- **No asumas librerías nuevas.** No agregues una librería sin que se pida explícitamente, salvo las ya adoptadas (PrimeVue 4 unstyled para tablas/diálogos, `barryvdh/laravel-dompdf` para generación de PDF).
- **No implementes roles/permisos** todavía, aunque el código lo sugiera como "next step" obvio.
- **No implementes borrado si no se pidió explícitamente.** Varios módulos evitaron a propósito el borrado hasta un paso dedicado (ej. CRUD de producto sin eliminar hasta CRUD de variante) — no lo adelantes solo porque "tiene sentido" agregarlo.
- **Un módulo nuevo no empieza hasta que el anterior esté funcionalmente cerrado.** Ej.: la UI de "Convertir a venta" no se construyó junto con el detalle/edición de Cotizaciones — se dejó como botón deshabilitado hasta que existió el módulo de Punto de Venta, para no terminar con una pantalla de Ventas a medias por fuera de su propio módulo.
- **Un hallazgo real durante un paso (bug, hueco de validación, caso borde no cubierto) se corrige en un paso aparte y nombrado, no se cuela dentro del paso en curso** — salvo que sea en el mismo módulo recién tocado y el costo de dejarlo sea real (ej. permitir convertir una cotización cancelada). Un bug en un módulo ya cerrado se anota como pendiente no bloqueante en `PROJECT.md` en vez de corregirse de pasada.
- Commits en inglés, Conventional Commits + gitmoji (ver `PROJECT.md` para la sintaxis y ejemplos).

## Entorno — cosas que rompen si no se respetan

- Usar **`localhost`**, nunca `127.0.0.1` (son orígenes distintos para el navegador y rompen CORS/Sanctum).
- No usar Laravel Valet por ahora (dominios `.test` rompen cookies `SameSite=Lax`).
- Antes de cualquier request que modifique estado en Sanctum (`POST`/`PUT`/`DELETE`), debe existir un `GET /sanctum/csrf-cookie` previo — si no, 419.
- `EnsureFrontendRequestsAreStateful` requiere el header `Origin`. En tests PHPUnit: `$this->withHeader('Origin', 'http://localhost:5173')`.
- `VITE_API_URL` en el frontend **no** lleva `/api` al final (el store ya arma `/api/login`, `/sanctum/csrf-cookie`, etc.).
- Logout debe usar `Auth::guard('web')->logout()` — el guard `sanctum` (RequestGuard) no tiene método `logout()`.
- Al crear un registro cuya tabla tiene columnas con `default` a nivel MySQL (ej. `stock_boxes` default 0), Eloquent no refresca el modelo automáticamente tras el `INSERT` — el atributo puede llegar `null` en la respuesta aunque la BD ya tenga el default aplicado. Llamar `->refresh()` después de `create()` en esos casos.
- La zona horaria de la app es `America/Mexico_City` (`config/app.php`), no `UTC` — cualquier lógica de fecha (filtros de rango, comparaciones de "hoy") debe asumir esta zona, no UTC. La API sigue serializando fechas en ISO-8601 UTC (`Z`) hacia el frontend; eso es comportamiento normal de Laravel al serializar, no un bug — `new Date(...)` en JS ya lo convierte a hora local.
- **dompdf no lee `.woff2`**, solo `.woff`, y descarta silenciosamente cualquier `@font-face` con `format()` distinto de `'truetype'` sin dar error. Para cualquier plantilla PDF nueva que use las fuentes de `@fontsource`, hay que tener copias `.woff` en `resources/fonts/` y omitir `format('woff')` en el `src` del `@font-face` — no "corregir" esto agregando el format, falla silenciosamente y el PDF cae a Helvetica/Times sin aviso.

## Convenciones de código (backend)

- **Nombres de tablas y columnas en inglés**, siempre — aunque el dominio de negocio y las conversaciones sean en español (ej. `products`, `product_variants`, `price_per_box`, no `productos`/`precio_caja`). Los valores de negocio visibles al usuario (labels en frontend, contenido de cotizaciones) sí pueden ir en español; el schema no.
- **Sin Repository Pattern.** Decisión explícita y deliberada — no agregar esta capa aunque parezca "buena práctica" de proyectos anteriores. Usar en su lugar:
  - **Query Scopes** nativos de Eloquent para filtros reusables (`scopeLowStock()`, `scopeByCategory()`, `scopeDateRange()`, etc.) definidos directamente en el modelo.
  - **Form Requests** dedicados para toda validación de entrada — nunca validar inline en el controlador.
  - **API Resources** para dar forma a las respuestas JSON de forma consistente.
  - Controladores delgados: reciben el Form Request ya validado, delegan a scopes/modelo, devuelven un Resource.
- **Catálogos de valores (categorías, tipos de unidad, proveedores, comisiones, etc.) van en tablas propias**, no como `enum` de MySQL ni strings hardcodeados — permite agregar/renombrar valores con un insert/update, sin migración ni deploy. Cada catálogo expone un `GET /api/{catalogo}` simple (sin paginación, solo `id`+`name`) para poblar selects del frontend. Ejemplos: `categories`, `unit_types`, `suppliers`, `commission_categories`.
- **Códigos/identificadores generados por el sistema nunca se aceptan desde el cliente** en un Form Request — se generan server-side (ver ejemplo `VariantCodeGenerator`) y se excluyen explícitamente de los campos validados en Store/Update.
- **Acciones de negocio con efecto específico van en un endpoint dedicado**, no como parte de un update genérico — ej. ajuste de stock (`PATCH /product-variants/{id}/stock` con `quantity`+`type`) en vez de permitir editar `stock_boxes` directamente vía `PUT`. Facilita agregar trazabilidad/historial después sin rediseñar.
- **Validaciones de negocio que dependen del modelo bindeado por ruta van en el controlador (guard clause), no en el Form Request.** Ningún Form Request de este proyecto tiene acceso al modelo resuelto por route-model-binding. Si una regla necesita comparar contra el estado actual del modelo antes de mutar (ej. "no eliminar la última variante activa de un producto", "no dejar `stock_boxes` negativo al hacer `subtract`"), va como validación explícita al inicio del método del controlador, antes de la mutación — no se fuerza esa lógica dentro del Form Request solo por consistencia formal.
- **Todo campo nuevo en un modelo necesita regla de validación explícita en AMBOS Form Requests relevantes (Store y Update).** Laravel descarta silenciosamente del `validated()` cualquier campo sin regla definida, sin importar que el cliente sí lo envíe en el body — esto ya causó un bug real (`stock_boxes` nunca llegaba a `create()`/`update()` por faltar la regla). No asumir cobertura por la migración o el Resource; verificar explícitamente los dos Form Requests.
- **`SoftDeletes`** en cualquier modelo que pueda quedar referenciado desde otro módulo en el futuro (ya aplicado a `Product`/`ProductVariant`, pensando en Cotizaciones/Ventas). Si el modelo tiene un servicio que valida unicidad de algún campo (ej. `code`), esa validación debe usar `withTrashed()` para no reutilizar valores de registros borrados lógicamente.
- **Un endpoint que muestra o reimprime un documento histórico (ej. `GET /sales/{id}`, `GET /sales/{id}/pdf`) carga sus relaciones con `withTrashed()`** cuando esas relaciones pueden haberse dado de baja desde que el documento se creó (ej. una variante o producto soft-deleted después de la venta) — el documento debe poder seguir viéndose/reimprimiéndose tal cual se generó, nunca mostrar datos vacíos o tronar con 500 porque la relación ya no existe en su forma activa.
- Si una tabla ya está migrada en un ambiente, cambios de estructura (como agregar `SoftDeletes`) van en una **migración nueva**, nunca editando una migración ya ejecutada.
- **Documentos con folio propio (ej. `Quote`/`Sale`) usan una secuencia de folio independiente por tipo de documento** (ej. `COT-0001`, `V-0001`), generada server-side igual que `code` en `ProductVariant` — nunca aceptada del cliente. No usar el `id` autoincremental como folio visible.
- **Convertir un documento en otro con efectos reales (ej. cotización → venta) NO es un endpoint que muta y crea de una sola vez.** Patrón establecido: un endpoint de solo lectura (ej. `GET /quotes/{id}/convert`) devuelve los datos prellenados para que el frontend los muestre editables, y la confirmación pasa por el endpoint de creación normal del documento destino (ej. `POST /sales`, el mismo que usa una venta directa), incluyendo la referencia de origen (`quote_id`) en el payload. Evita duplicar lógica de validación/creación entre el flujo directo y el flujo convertido.
- **Entidades con ciclo de vida y reglas de edición distintas (borrador vs. documento concretado) van en tablas separadas**, no en una sola tabla con `status` genérico — ver `Quote`/`Sale` en `PROJECT.md`. Se acepta duplicar estructura entre tablas de líneas relacionadas (ej. `quote_items`/`sale_items`) en vez de una tabla polimórfica compartida, consistente con "sin indirección extra".
- **Guards de negocio reusables entre endpoints (ej. validar/descontar stock, bloquear una conversión inválida) van como método público en el modelo, no duplicados en cada controlador.** Ejemplos: `ProductVariant::hasSufficientStock()`/`decrementStock()`, usados tanto por el ajuste de stock manual como por la creación de ventas; `Quote::conversionBlockedMessage()`, usado tanto por `QuoteController@convert` como por `SaleController@store` — mismo guard, mismo mensaje de error, una sola fuente de verdad. Cuando el guard es "rechazar todo lo que no sea el estado X", prefiere expresarlo así (negativo) en vez de enumerar cada estado no permitido — cubre automáticamente un estado nuevo que se agregue al catálogo sin tener que tocar el guard de nuevo.
- **Cuando una cantidad de línea (`quantity`) está en una unidad de venta distinta a la unidad en la que vive el stock** (ej. `quantity` en m² vía `price_per_m2`, `stock_boxes` en cajas), la conversión se hace explícita en el punto donde se compara/descuenta contra stock — nunca se asume una equivalencia 1:1 silenciosa. Redondear hacia arriba (`ceil`) al convertir a la unidad física de stock (no se puede descontar media caja), pero el monto cobrado (`unit_price`/`line_total`) siempre se calcula sobre la cantidad exacta solicitada, no sobre la cantidad redondeada. Si falta el factor de conversión en el registro (ej. `m2_per_box` null), rechazar la línea con 422 explícito en vez de asumir 1:1.
- **Shortcut `?with=` para eager loading**: dos variantes coexisten hoy — match exacto de string (`ProductController`, ej. `?with=variants`) y
  comma-separated con whitelist explícita (`QuoteController`, `SaleController`, ej. `?with=customer,quoteStatus`). Usar la variante whitelist para cualquier endpoint nuevo que necesite eager-loadear más de una relación a la vez; valores fuera de whitelist se ignoran silenciosamente, nunca error 422.
- **Un recurso puede exponer una relación anidada solo cuando está cargada, vía `whenLoaded`**, sin forzar su carga por defecto en todos los endpoints que usan ese Resource. Ejemplo: `ProductVariantResource.product` (id+name de la línea) solo aparece cuando el controller cargó explícitamente `.product` (como hace `QuoteController@show` para el detalle de cotización) — otros endpoints que devuelven el mismo Resource sin esa relación cargada no pagan el costo ni rompen.
- **Un servicio que produce un documento compartido entre entidades distintas (ej. generación de PDF para `Sale` y `Quote`) recibe una estructura de datos neutral, no un modelo Eloquent.** Ver `DocumentPdfGenerator`: toma `{ document_type, folio, date, customer, items, subtotal, total }` armado por cada controlador, no `Sale`/`Quote` directamente — evita que el servicio quede acoplado a un solo modelo y permite reusarlo sin cambios cuando el segundo caso de uso llega.

## Convenciones de código (frontend)

- Componentes Vue: `PascalCase.vue`
- Composables/stores: `camelCase.js`, stores de Pinia con nombre descriptivo (`useAuthStore`, no `useStore`)
- Patrón ya establecido en `auth.js`: flag `initialized` para evitar refetch innecesario de datos en cada navegación — replicar este patrón en stores futuros que dependan de datos poco cambiantes (ya replicado en `inventory.js`, `catalogs.js` y `quotes.js`). **Excepción**: cuando una acción de fetch depende de un filtro que cambia legítimamente entre llamadas (ej. `sales.fetchSales({ from, to })` con filtro de fecha), `initialized` no debe usarse como gate de la petición — cada combinación de filtro es una consulta distinta, no "la misma lista otra vez". En ese caso, considera un contador de petición para descartar respuestas tardías si el filtro cambia antes de que responda una anterior.
- **Mutaciones puntuales actualizan el store in-place, sin refetch completo.** Cuando una acción afecta un solo registro (ej. ajustar stock de una variante, eliminar una fila, editar una cotización, descontar stock tras una venta), actualiza solo ese registro dentro del array del store con el valor final — un refetch completo colapsaría filas/grupos que el usuario ya tenía expandidos en la UI. Patrón ya usado en `updateVariantStock()`, en `quotes.updateQuote()`, y en la sincronización de stock de Inventario tras una venta en POS.
- **Acciones con endpoint dedicado se ejecutan de inmediato al confirmarlas, no se difieren hasta el submit de un formulario contenedor** (ej. eliminar una variante existente dentro del form de edición de producto dispara el `DELETE` al momento, no espera al guardar el resto del form) — evita tener que implementar diffing de estado entre lo cargado y lo enviado.
- **Acciones destructivas requieren confirmación vía `ConfirmDialog` de PrimeVue**, nunca `confirm()` nativo del navegador — rompe la seriedad visual del sistema de diseño. Acciones no destructivas o fácilmente reversibles (ej. ajustar stock) no necesitan este paso extra.
- **Un formulario reusado entre creación y edición (o entre modos relacionados, ej. venta directa vs. convertir cotización) detecta el modo por la presencia de un identificador en la ruta** (`route.params.id` o un query param como `?quote_id=`), en vez de duplicar el componente — mismo template, carga de datos y comportamiento de submit cambian según el modo detectado.
- **Un guard de negocio que vive en el backend (ej. "solo editable en Borrador", "solo convertible en Borrador") se replica como check de UX en el frontend, pero nunca como la única validación.** El frontend puede redirigir, deshabilitar un botón, u ocultar un formulario para no mostrar una acción que el backend va a rechazar, pero la fuente de verdad sigue siendo el 422 del backend.
- **Un caso borde detectado solo en caché/estado local del frontend (ej. una variante que existe en BD pero no en el store cacheado) puede intentar resolverse con un refetch puntual antes de bloquear** (una sola vez, nunca en loop) — distinto a un rechazo real del backend, que siempre se muestra tal cual sin intentar "arreglarlo" del lado del cliente.
- Layouts en `src/layouts/`, vistas en `src/views/<módulo>/`, componentes reutilizables en `src/components/`.
- Guards de router centralizados en `src/router/index.js`, no dispersos por vista.
- **Convención de nombres en inglés aplicada solo al código** (archivos, carpetas, componentes, nombre interno de ruta) — NO al contenido de negocio visible al usuario (labels, placeholders) ni a los paths de URL (esos se quedan en español, ej. `/inventario`, `/cotizaciones`).
- **Estructura fija de bloques en todo `.vue`** (existentes y futuros): siempre `template` → `script setup` → `style scoped`, en ese orden, sin excepción, aunque un bloque quede vacío.
- **Tablas de datos y diálogos: PrimeVue 4 (MIT, modo unstyled)** — nunca v5, por su cambio a licenciamiento PrimeUI (requiere licencia o muestra watermark). Estilos vía `:deep()` sobre elementos HTML nativos o markup propio en slots (ej. `#container`), no vía la prop `pt` salvo para piezas sin markup propio en modo unstyled (ej. el `mask`/overlay de un `Dialog`) — las keys internas del `pt` son poco confiables entre versiones.
- **Componentes reutilizables van en `src/components/widgets/<tipo>/`** (ej. `autocompletes/`, `inputs/`, `selects/`, `buttons/`, `dialogs/`) — un componente se extrae ahí cuando se va a reusar en un módulo nuevo (no de forma retroactiva en módulos ya cerrados, salvo que se toquen por otra razón). Primera extracción: `VariantAutocomplete.vue` y `CustomerSearch.vue`, sacados de `QuoteFormView.vue` para reusarse en POS. `VariantAutocomplete.vue` usa un evento emitido (`select(option)`) en vez de `v-model` para la variante elegida, porque la lógica de fusión de variante duplicada en el padre a veces necesita eliminar la fila en vez de asignarle la variante, algo que un `v-model` interferiría al escribir el valor primero.
- **Un "hub" de módulo (ej. `PosView.vue`) empieza mínimo, con solo los accesos que ya tienen una pantalla real detrás** — no se construye una grilla de opciones anticipando pantallas que todavía no existen; se amplía un acceso a la vez conforme la pantalla correspondiente queda lista.

```vue
<template></template>

<script setup></script>

<style scoped></style>
```

- Si un componente no necesita estilos propios, el bloque `<style scoped>` se deja vacío — no se omite.
- No usar `<script>` sin `setup` en ningún archivo nuevo o existente.
- No usar `<style>` global (sin `scoped`) salvo que sea justificado y explícito (no debería ser el caso en este proyecto).

## Sistema de diseño

Dirección: **corporativo serio** (banca/legal), no startup ni SaaS "friendly". Consistencia > creatividad — cualquier pantalla nueva debe verse como si viniera del mismo diseñador.

### Implementación técnica (Tailwind v4)

Este proyecto usa **Tailwind v4** — no hay `tailwind.config.js`. Los tokens de color y tipografía viven en un bloque `@theme` dentro del CSS de entrada (donde está `@import "tailwindcss";`, normalmente `src/style.css` o `src/assets/main.css`). **No crear `tailwind.config.js` para esto** — si algún día se necesita para un plugin específico, se agrega aparte, pero los tokens de diseño quedan siempre en `@theme`.

Fuentes instaladas vía `@fontsource` (self-hosted, no CDN de Google Fonts):

```
npm install @fontsource/inter @fontsource/source-serif-4
```

Importadas en `main.js` (pesos 400/500/600/700 de Inter, 400/600 de Source Serif 4). Para plantillas PDF (dompdf), ver nota de `.woff`/`format()` en "Entorno — cosas que rompen si no se respetan".

### Paleta (variables CSS definidas en `@theme`, nunca hex sueltos en componentes)

| Token          | Hex       | Uso                                                                                                              |
| -------------- | --------- | ---------------------------------------------------------------------------------------------------------------- |
| `primary`      | `#14293D` | Sidebar, headers, botones primarios, texto sobre fondo claro en marca                                            |
| `primary-dark` | `#0B1A29` | Hover/active de elementos `primary`                                                                              |
| `accent`       | `#A67C3D` | Focus rings, indicador de sección/nav activa. Uso **limitado** — no decorativo, no botones grandes de este color |
| `bg`           | `#F7F8FA` | Fondo general de la app                                                                                          |
| `surface`      | `#FFFFFF` | Cards, modales, inputs                                                                                           |
| `border`       | `#E4E7EC` | Bordes de cards, tablas, inputs                                                                                  |
| `text`         | `#1D2939` | Texto principal                                                                                                  |
| `text-muted`   | `#667085` | Texto secundario, placeholders, labels                                                                           |
| `success`      | `#2F6844` | Confirmaciones, estados positivos                                                                                |
| `danger`       | `#A13D3D` | Errores, estados destructivos                                                                                    |

### Tipografía

- **UI general** (botones, forms, tablas, nav, body): **Inter**. Es la fuente por defecto para todo excepto lo indicado abajo.
- **Wordmark / títulos de página** (`<h1>` de cada vista, logo "PV"/"PAVH"): **Source Serif 4**. Uso restringido — nunca en botones, labels, ni texto de tabla. Es el único lugar donde aparece un serif.
- Tamaño base 16px, escala modesta (no tipografía gigante tipo landing page — esto es una herramienta de trabajo).

### Layout

- Sidebar fijo, fondo `primary`, íconos + labels en blanco/gris claro.
- Topbar blanco, borde inferior `border` (1px, sin sombra).
- Contenido sobre fondo `bg`, cards en `surface` con borde `border` de 1px — **no usar `box-shadow` pesado**, rompe la seriedad del diseño.
- Border-radius pequeño: `4px`–`6px` en cards, inputs y botones. Nunca `rounded-full` en botones (se siente demasiado "startup").
- Indicador de item activo en el sidebar: barra delgada de 2-3px en `accent` al lado izquierdo del item — es el único acento de color vivo permitido fuera de estados (success/danger).

### Componentes base ya definidos

- **Forms** (patrón establecido en `Login.vue`, replicado en `ProductFormView.vue`, `QuoteFormView.vue` y `SaleFormView.vue`): card centrada o de ancho completo según contexto, inputs con ícono a la izquierda cuando aplica, botón primario de color sólido (sin gradientes), estado de error inline (no toast para errores de validación de campo). El error inline no debe ocultar el resto del formulario (ver bug conocido de `QuoteFormView.vue` en `PROJECT.md` — evitar repetirlo en formularios nuevos).
- **Listas repetibles dentro de un form** (patrón de colores en `ProductFormView.vue`, líneas de producto en `QuoteFormView.vue` y `SaleFormView.vue`): campos compartidos se capturan una sola vez; el campo que varía se captura como lista repetible con botón "+ Agregar" y botón de quitar por fila (deshabilitado si solo queda 1 fila).
- **Tablas agrupadas con expansión** (patrón de `InventoryView.vue`): PrimeVue `DataTable` con row-expansion nativo vía slots (`#body`/`#expansion`), agrupación visual hecha con un helper de JS puro (no de PrimeVue) cuando el agrupamiento depende de reglas de negocio específicas (ver `groupVariants.js`).
- **Listados simples con filtros rápidos** (patrón de `SalesView.vue`): `DataTable` sin expansión, con botones de filtro rápido (ej. "Hoy"/"Esta semana"/"Este mes") en vez de inputs de fecha libres cuando los rangos son predecibles, resaltando visualmente el filtro activo (borde `accent`, texto más oscuro, `aria-pressed`). Búsqueda adicional client-side sobre el resultado ya cargado, combinable con el filtro activo.
- **Vista de detalle de un documento** (patrón de `QuoteDetailView.vue` y `SaleDetailView.vue`): header con folio/identificador + badge de status (si aplica) + fecha, card de "datos generales", card de tabla de líneas con totales al final, acciones a la derecha del header (algunas pueden existir deshabilitadas con tooltip explicando por qué, en espera de un módulo futuro; otras condicionadas a soporte del navegador, ej. "Compartir" solo si `navigator.canShare` lo permite).
- **Confirmación de acciones destructivas**: `ConfirmDialog` de PrimeVue unstyled, con markup propio vía slot `#container` y los tokens de color del sistema de diseño.
- **Diálogos de acción puntual** (ej. ajuste de stock): `Dialog` de PrimeVue unstyled, mismo criterio visual que `ConfirmDialog`.
- **Documentos imprimibles/compartibles** (ej. nota de venta): generados server-side en PDF vía `DocumentPdfGenerator` (ver convenciones de backend), no `window.print()` del navegador — el caso de uso real es compartir el documento como archivo (ej. WhatsApp), no solo imprimirlo en el momento. En el frontend, el botón "Compartir" usa Web Share API cuando el navegador lo soporta y se oculta (no se deshabilita) cuando no — nunca mostrar una acción que fallaría al tocarla.

## Checklist antes de dar por terminada una tarea

- [ ] ¿Usa los tokens de color definidos arriba (no hex sueltos)?
- [ ] ¿Usa Inter para UI y Source Serif 4 solo en títulos de página?
- [ ] ¿Sigue el patrón de layout (sidebar/topbar/cards sin sombra pesada)?
- [ ] ¿El commit sigue Conventional Commits + gitmoji en inglés?
- [ ] ¿No introduce una librería nueva sin pedirlo (más allá de PrimeVue 4 y `laravel-dompdf`, ya adoptadas), Repository Pattern, o roles/permisos sin que se haya pedido?
- [ ] ¿No introduce borrado ni ninguna otra acción destructiva sin que se haya pedido explícitamente?
- [ ] ¿No empieza un módulo nuevo a medias dentro del trabajo de otro módulo?
- [ ] ¿Un hallazgo nuevo (bug, caso borde) se resolvió en un paso aparte y nombrado, o se anotó como pendiente en `PROJECT.md` si el módulo ya estaba cerrado, en vez de colarse dentro del paso en curso?
- [ ] ¿Usa `localhost` (no `127.0.0.1`) en cualquier URL de config?
- [ ] (Backend) ¿Tablas/columnas nuevas están en inglés? ¿Catálogos de valores van en tabla propia, no hardcodeados?
- [ ] (Backend) ¿Los identificadores generados por el sistema quedan excluidos de los Form Requests de entrada?
- [ ] (Backend) ¿Todo campo nuevo tiene regla de validación en AMBOS Form Requests (Store y Update)?
- [ ] (Backend) ¿Las validaciones que dependen del modelo bindeado por ruta están en el controlador, no forzadas dentro del Form Request?
- [ ] (Backend) ¿Documentos con folio (`Quote`/`Sale`) generan su folio server-side con secuencia propia, nunca vía `id` ni aceptado del cliente?
- [ ] (Backend) ¿Una conversión entre documentos (cotización → venta) reutiliza el endpoint de creación normal en vez de mutar/crear todo en un solo paso?
- [ ] (Backend) ¿Si hay conversión de unidad entre cantidad vendida y stock (ej. m² vs cajas), se hace explícita en el punto de comparación/descuento, sin asumir 1:1?
- [ ] (Backend) ¿Una relación anidada nueva en un Resource usa `whenLoaded` en vez de cargarse siempre por defecto?
- [ ] (Backend) ¿Un endpoint que muestra/reimprime un documento histórico carga sus relaciones con `withTrashed()` cuando pudieron darse de baja después?
- [ ] (Backend) ¿Un guard de negocio reusable se expresa como "rechazar todo lo que no sea el estado válido" en vez de enumerar cada estado inválido, cuando aplica?
- [ ] (Frontend) ¿Las mutaciones puntuales actualizan el store in-place en vez de refetch completo?
- [ ] (Frontend) ¿Las acciones destructivas usan `ConfirmDialog` de PrimeVue, no `confirm()` nativo?
- [ ] (Frontend) ¿Un guard de negocio del backend tiene su contraparte de UX en el frontend (deshabilitar/redirigir/ocultar), sin duplicar la validación real?
- [ ] (Frontend) ¿Un componente reutilizado entre módulos vive en `src/components/widgets/<tipo>/`, no duplicado o inline?
- [ ] (Frontend) ¿Un fetch con filtro variable (ej. rango de fecha) evita el gate de `initialized` en vez de cachear indebidamente?
