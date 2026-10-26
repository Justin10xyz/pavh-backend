# AGENT.md — Reglas para IA trabajando en PAVH

Para cualquier agente (Claude Code, Cursor, etc.) que trabaje en `pavh-backend` o `pavh-frontend`. Léelo antes de generar código.

## Reglas generales de trabajo

- **Pedir pasos acotados, no tareas abiertas.** Justin prefiere prompts como "crea la instancia de axios" en vez de "conecta todo el frontend con el backend". Una tarea grande se divide en pasos nombrados y secuenciales antes de escribir código; un paso muy grande también se divide (ej. crear vs. editar en pasos separados).
- **Backend antes que frontend** cuando haya dependencia entre ambos.
- **Frontend: empezar por lo visible** — UI visible → router → config/servicios → estado (Pinia) → guards.
- **No asumir librerías nuevas** sin que se pidan explícitamente, salvo las ya adoptadas (PrimeVue 4 unstyled, `barryvdh/laravel-dompdf`).
- **No implementar roles/permisos** (decidido que no van en el roadmap) ni **borrado sin que se pida explícitamente**, aunque parezca el siguiente paso obvio.
- **Un módulo nuevo no empieza hasta que el anterior esté funcionalmente cerrado.**
- **Un hallazgo real durante un paso (bug, hueco de validación) se corrige en un paso aparte y nombrado**, no se cuela en el paso en curso — salvo que sea en el mismo módulo recién tocado y el costo de dejarlo sea real. Un bug en un módulo ya cerrado se anota en `PROJECT.md` como pendiente, no se corrige de pasada.
- Commits en inglés, Conventional Commits + gitmoji (sintaxis y ejemplos en `PROJECT.md`).

## Entorno — cosas que rompen si no se respetan

- Usar **`localhost`**, nunca `127.0.0.1` (orígenes distintos → rompe CORS/Sanctum).
- No usar Laravel Valet (dominios `.test` rompen cookies `SameSite=Lax`).
- Toda request que modifique estado en Sanctum necesita un `GET /sanctum/csrf-cookie` previo, o da 419.
- `EnsureFrontendRequestsAreStateful` requiere header `Origin`. En tests PHPUnit: `$this->withHeader('Origin', 'http://localhost:5173')`.
- `VITE_API_URL` **no** lleva `/api` al final.
- Logout usa `Auth::guard('web')->logout()` — el guard `sanctum` no tiene `logout()`.
- Columnas con `default` a nivel MySQL (ej. `stock_boxes` default 0) no se refrescan solas tras `create()` — llamar `->refresh()`.
- Zona horaria de la app: `America/Mexico_City`, no `UTC`. La API sigue serializando en ISO-8601 UTC (`Z`); es normal, `new Date()` en JS ya lo convierte.
- **dompdf** solo lee `.woff` (no `.woff2`) y descarta en silencio cualquier `@font-face` con `format()` distinto de `'truetype'` — en plantillas PDF nuevas con fuentes de `@fontsource`, usar copias `.woff` y omitir `format('woff')`. También necesita `ext-gd` para PNGs con transparencia (logos).
- **Vite puede quedarse con una versión intermedia de un `.vue`** tras varias ediciones seguidas (`ReferenceError` que no coincide con el archivo real). Si `vite build` pasa, no es error de código: recargar con Ctrl+Shift+R, o `touch`/reiniciar `npm run dev`.

## Convenciones de código (backend)

- **Nombres de tablas y columnas en inglés** siempre; los valores de negocio visibles (labels, contenido de documentos) sí van en español.
- **Sin Repository Pattern** — decisión deliberada. En su lugar: **Query Scopes** de Eloquent para filtros reusables, **Form Requests** para toda validación de entrada, **API Resources** para las respuestas, controladores delgados que delegan a scopes/modelo.
- **`Controllers`/`Requests` organizados en subcarpetas por módulo** (`Inventory/`, `Quote/`, `Customer/`), namespace reflejando la carpeta; `Store`/`Update` de un recurso viven juntos. Al mover un Form Request, correr la suite completa (`php artisan test`), no solo el módulo tocado.
- **Catálogos de valores van en tablas propias**, no `enum` de MySQL ni strings hardcodeados — cada uno expone un `GET /api/{catalogo}` simple para selects. Ejemplos: `categories`, `unit_types`, `suppliers`, `commission_categories`. Pueden ganar `POST`/`PUT` propios sin dejar de ser catálogo.
- **Identificadores generados por el sistema nunca se aceptan del cliente** (ej. `VariantCodeGenerator`, folios) — se excluyen explícitamente de los Form Requests.
- **Acciones de negocio con efecto específico van en endpoint dedicado**, no en un update genérico (ej. `PATCH /product-variants/{id}/stock` en vez de editar `stock_boxes` vía `PUT`) — facilita agregar trazabilidad después.
- **Un campo nullable donde NULL = "no aplica" nunca se inicializa con default**, y cualquier transición hacia/desde null pasa por el mismo mecanismo de historial que los demás cambios (ver stock opcional: `type: add`/`deactivate` + `StockMovement`).
- **Validación que depende del modelo bindeado por ruta va en el controlador (guard clause), nunca en el Form Request** (ningún Form Request tiene acceso al modelo resuelto por route-model-binding) — ej. "no eliminar la última variante activa". Si en cambio depende de **otro campo del mismo payload** (aunque haya que consultar el registro al que apunta), sí va en el Form Request dentro de `rules()`; si ese campo es editable en Update, la regla pasa a guard en el controlador comparando contra el modelo bindeado.
- **Normalizar formato de entrada (mayúsculas, etc.) va en `prepareForValidation()`**, en Store y Update si aplica a ambos — así `unique` compara contra lo que de verdad se guarda.
- **Todo campo nuevo necesita regla de validación explícita en AMBOS Form Requests.** Laravel descarta en silencio del `validated()` cualquier campo sin regla, aunque el cliente lo envíe — no asumir cobertura por la migración o el Resource.
- **`SoftDeletes`** en cualquier modelo que pueda quedar referenciado desde otro módulo. Validaciones de unicidad sobre ese modelo usan `withTrashed()`.
- **Un endpoint que reimprime un documento histórico carga sus relaciones con `withTrashed()`** — el documento debe seguir viéndose igual aunque una relación se haya dado de baja después.
- Cambios de estructura sobre una tabla ya migrada van en **migración nueva**, nunca editando una ya ejecutada.
- **Documentos con folio (`Quote`/`Comprobante`) usan secuencia independiente por tipo** (`COT-0001`, `CF-0001`), server-side, nunca el `id` ni aceptada del cliente.
- **Convertir un documento en otro con efectos reales NO muta y crea de una sola vez.** Patrón: un endpoint de solo lectura prellena datos editables (ej. generar un Comprobante a partir de un Presupuesto pagado), y la confirmación pasa por el endpoint de creación normal del documento destino, con la referencia de origen en el payload.
- **Entidades con ciclo de vida y reglas de edición distintas (borrador vs. concretado) van en tablas separadas**, no una tabla con `status` genérico (ver `Quote`/`Comprobante`). Se acepta duplicar estructura entre tablas de líneas relacionadas en vez de polimorfismo compartido.
- **Guards de negocio reusables entre endpoints van como método público en el modelo**, no duplicados por controlador (ej. `ProductVariant::hasSufficientStock()`, `Quote::conversionBlockedMessage()`). Cuando el guard es "rechazar todo lo que no sea el estado X", exprésalo así (negativo) en vez de enumerar estados inválidos.
- **Se vende y se cobra en la unidad física de stock, no en la unidad del precio.** Variantes: cantidad real = `boxes` (entero), descuenta `stock_boxes`; el precio sigue en m² (`price_per_m2`), así que los m² de línea (`quantity`) los deriva el backend (`boxes × m2_per_box`) y nunca se aceptan del cliente. Si falta `m2_per_box`, 422 explícito — nunca asumir 1:1.
  - **Excepción — pisos por pieza** (`unit_type` "pieza"): `boxes` cuenta piezas, `quantity = boxes` sin derivar m², precio = `price_per_box`. El criterio vive en un solo lugar por capa: `Product::isSoldByPiece()`/`ProductVariant::isSoldByPiece()` (backend) y `src/lib/unitTypes.js` (frontend) — siempre comparando el **nombre** del `unit_type`, nunca un id.
- **Eager loading con `?with=`**: usar la variante whitelist comma-separated (`?with=customer,quoteStatus`) para cualquier endpoint nuevo con más de una relación — valores fuera de whitelist se ignoran en silencio, nunca 422.
- **Una relación anidada en un Resource se expone solo si está cargada, vía `whenLoaded`**, sin forzarla por defecto. `index` y `show` del mismo recurso con el mismo `?with=` deben cargar las mismas relaciones anidadas, o el dato desaparece en silencio en el que no la carga.
- **Un partial de documento compartido entre plantillas PDF (ej. `sheet-styles.blade.php`/`items-table.blade.php`, usados por `QuotePdfGenerator` y `ComprobantePdfGenerator`) recibe datos ya preparados**, sin armar lógica de negocio dentro del blade.
- **Un catálogo que no encaja en producto+variante usa su propia tabla paralela** (ej. `simple_products` para categorías `simple`), no se fuerza con columnas que no aplicarían.
- **Cambiar un campo que determina la forma de registros dependientes se bloquea si ya existen dependientes** — guard clause que devuelve el mensaje o `null`, sin contar soft-deleted (ej. `productFormTypeChangeBlockedMessage()`, `unitTypeChangeBlockedMessage()`). Rechazo 422 con `message`, sin `errors`.
- **Un catálogo referenciado por registros con soft delete se borra también con soft delete** (la FK de los dados de baja impide el borrado real). Al agregar `SoftDeletes` a un catálogo: relaciones hacia él con `withTrashed()`, `exists` con `->withoutTrashed()`, `unique` con `->withoutTrashed()`.

## Convenciones de código (frontend)

- Componentes Vue: `PascalCase.vue`; composables/stores: `camelCase.js`, nombre descriptivo (`useAuthStore`, no `useStore`).
- **Flag `initialized` en stores** para evitar refetch innecesario (patrón de `auth.js`, replicado en `inventory.js`/`catalogs.js`/`quotes.js`). **Excepción**: un fetch con filtro que cambia legítimamente entre llamadas (ej. rango de fecha) no debe usar `initialized` como gate — considerar un contador de petición para descartar respuestas tardías.
- **Mutaciones puntuales actualizan el store in-place**, sin refetch completo — un refetch colapsaría filas/grupos ya expandidos en la UI.
- **Acciones con endpoint dedicado se ejecutan de inmediato al confirmarlas**, no se difieren hasta el submit del form contenedor.
- **Acciones destructivas usan `ConfirmDialog` de PrimeVue**, nunca `confirm()` nativo. Acciones reversibles (ajustar stock) no necesitan este paso.
- **Un formulario reusado entre crear/editar detecta el modo por un identificador en la ruta** (`route.params.id`, `?quote_id=`) o por si recibe el registro como prop, en vez de duplicar el componente.
- **Un guard de negocio del backend se replica como check de UX en el frontend, pero nunca como la única validación** — la fuente de verdad sigue siendo el 422 del backend.
- Layouts en `src/layouts/`, vistas en `src/views/<módulo>/`, componentes reutilizables en `src/components/`.
- **Guards de router centralizados en `src/router/index.js`.** Ruta privada: `meta.requiresAuth`; ruta solo-invitado: `meta.guestOnly`. `?redirect=` acepta solo rutas internas. Título de pestaña vía `meta.title`.
- **Sesión vencida**: un solo interceptor (`src/lib/sessionExpiry.js`, 401/419 → `auth.expireSession()` → `login?redirect=`). Toda petición pasa por `src/lib/axios.js`, nunca `axios` directo.
- **Mensajes de error por status**, no `err.response.data.message` tal cual salvo cuando el backend lo arma en español (422 de negocio, 429 de login) — con `APP_LOCALE=en` el resto llega en inglés/técnico.
- **Nombres en inglés solo en código** (archivos, componentes, nombre interno de ruta) — nunca en contenido visible ni en paths de URL (esos quedan en español, ej. `/inventario`).
- **Estructura fija en todo `.vue`**: `template` → `script setup` → `style scoped`, siempre en ese orden aunque un bloque quede vacío; sin `<script>` sin `setup`; sin `<style>` global salvo justificado.
- **PrimeVue 4 (MIT, unstyled)** — nunca v5 (licenciamiento PrimeUI). Estilos vía `:deep()` o markup propio en slots, no la prop `pt` salvo piezas sin markup propio. En modo unstyled no hay clases `p-*`: usar `data-pc-section` (ej. `[data-pc-section="rowexpansion"]`).
- **Componentes reutilizables entre módulos van en `src/components/widgets/<tipo>/`** (`autocompletes/`, `inputs/`, `selects/`, `dialogs/`, `tables/`, `badges/`, etc.), extraídos cuando se reusan en un módulo nuevo (no retroactivo). Uno que solo vive en un módulo va en `src/views/<módulo>/components/`.
- **Un componente que crece demasiado (~500 líneas, o varias secciones con identidad propia) se divide, no se sigue engordando:**
  - La vista coordina (carga, validación de conjunto, submit, confirmaciones, navegación); los subcomponentes no llaman API ni navegan.
  - Cada sección visual es un subcomponente, con `defineModel` para estado editable compartido y props/eventos para el resto.
  - La lógica pura sale a `src/lib/<tema>.js` (sin `axios`, sin stores, sin refs de la vista).
  - Lo que lee stores y necesitan vista + subcomponentes va en un composable `src/composables/use<Tema>.js`.
  - Si dos vistas repiten la misma sección, se extrae una sola vez compartida (diferencias por slot/prop/evento) antes de dividir cada una por su lado.
  - Una división es un paso propio de refactor sin cambios de comportamiento, verificado con build + prueba manual — nunca mezclado con una corrección. No se divide si las piezas quedarían acopladas (props/eventos que solo pasan todo de un lado a otro).
- **Un "hub" de módulo empieza mínimo**, solo con accesos que ya tienen pantalla real detrás — se amplía conforme cada pantalla queda lista.
- **Un componente de acción puntual se generaliza vía props** cuando aparece un segundo consumidor con forma de dato distinta, en vez de duplicarlo (ej. `StockAdjustDialog.vue` entre `ProductVariant` y `SimpleProduct`).

```vue
<template></template>

<script setup></script>

<style scoped></style>
```

## Sistema de diseño

Dirección: **corporativo serio** (banca/legal), no startup. Consistencia > creatividad.

**Tailwind v4** — sin `tailwind.config.js`; tokens de color/tipografía en un bloque `@theme` del CSS de entrada. Fuentes vía `@fontsource` (Inter + Source Serif 4, self-hosted, no CDN).

### Paleta (siempre tokens de `@theme`, nunca hex sueltos en componentes)

| Token          | Hex       | Uso                                                                          |
| -------------- | --------- | ----------------------------------------------------------------------------- |
| `primary`      | `#1C1B19` | Sidebar, headers, botones primarios                                          |
| `primary-dark` | `#11100F` | Hover/active de elementos `primary`                                          |
| `accent`       | `#A67C3D` | Focus rings, nav activa. Uso **limitado** — no decorativo, no botones grandes |
| `bg`           | `#F7F8FA` | Fondo general                                                                |
| `surface`      | `#FFFFFF` | Cards, modales, inputs                                                      |
| `border`       | `#E4E7EC` | Bordes de cards, tablas, inputs                                             |
| `text`         | `#1D2939` | Texto principal                                                              |
| `text-muted`   | `#667085` | Texto secundario, placeholders, labels                                      |
| `success`      | `#2F6844` | Confirmaciones                                                              |
| `danger`       | `#A13D3D` | Errores, destructivo                                                        |
| `commission-1`…`5` | `#1F5F3B` `#7FB069` `#E0B83A` `#DD8A2E` `#B23A3A` | Códigos de comisión por `tier` (1 Vo … 5 R). Solo como punto en `CommissionBadge.vue`, junto al código escrito; el color sale del nivel que manda el backend, nunca del código a mano en frontend. |

`primary` es grafito cálido casi negro (no volver a navy — chocaba con el logo INNOVA).

### Tipografía y marca

- UI general (botones, forms, tablas, body): **Inter**. Títulos `<h1>` de cada vista: **Source Serif 4**, único uso de serif. Base 16px, escala modesta.
- **Nombre visible: INNOVA** — solo branding visible (`<title>`, sufijo de pestaña, `alt` de logos); repos, schema, namespaces, rutas y estos docs siguen diciendo PAVH. El wordmark va dentro de la imagen del logo, nunca como `<span>` aparte.
- **Assets**: `src/assets/branding/` (frontend), `resources/images/branding/` (backend/dompdf), favicons en `public/`. Variante de logo según fondo: lockup completo con tagline en fondo blanco con espacio (Login); ícono + nombre en blanco sobre `primary` (sidebar expandido); solo ícono (sidebar colapsado, `< lg`); versión sin tagline para PDF (espacio reducido).

### Layout y responsive

- Sidebar fijo `primary` (solo íconos `w-16` bajo `lg`, expandido con labels `w-50` desde `lg`, CSS puro mobile-first); topbar blanco con borde inferior; contenido sobre `bg`, cards `surface` con borde 1px — **sin `box-shadow` pesado** salvo paneles flotantes (`shadow-lg`). Border-radius pequeño (`rounded`/`rounded-md`), nunca `rounded-full` en botones. Indicador de item activo en sidebar: barra `accent` de 2-3px — único acento de color vivo fuera de success/danger/comisión.
- **Unidades en `rem`** (utilidades con nombre de Tailwind: `p-4`, `h-10`, `rounded-md`, `text-sm`), nunca `px` arbitrario, salvo bordes de 1px. Altura estándar de controles: `h-10`.
- **Breakpoints default de Tailwind v4** (`sm` 640px, `md` 768px, `lg` 1024px, `xl` 1280px), sin custom. **Mobile-first obligatorio**: clase sin prefijo = base, prefijos amplían hacia arriba, nunca `max-sm:` para encoger.
- **Objetivo responsive**: herramienta de escritorio en primer lugar. No es objetivo adaptar sidebar/topbar a ancho de teléfono (`< sm`) todavía.

### Componentes base ya definidos

- **Forms**: card con inputs con ícono a la izquierda cuando aplica, botón primario sólido, error inline (nunca toast para validación de campo) que no oculta el resto del form. Botón de guardar no se deshabilita por campos faltantes — se valida al enviar y lo que falta se marca bajo su campo. Un 422 sin `errors` que corresponde a un campo va bajo ese campo; si no, al error general. Leer siempre `err.response.data.errors ?? {}`. Form en diálogo enfoca su primer campo al abrir.
- **Captura con teclado en forms largos**: Enter avanza de campo (`src/lib/formKeyboard.js`) en vez de enviar; Ctrl/⌘+S y Ctrl/⌘+Enter guardan (`useSaveShortcut`, con `SaveShortcutHint.vue`); error de validación lleva el foco al primer `aria-invalid` (`focusFirstInvalidField`). En listas repetibles, agregar/quitar fila mueve el foco a la fila relevante, nunca al `<body>`.
- **Buscadores con resultados** (`ProductAutocomplete.vue`, `CustomerSearch.vue`): patrón ARIA combobox completo (roles, `aria-expanded`/`aria-activedescendant`, navegación con teclado, `scrollIntoView`), opciones elegidas con `@mousedown.prevent`.
- **Mostrar/ocultar contraseña**: botón dentro del input, nombre fijo "Mostrar contraseña" con `aria-pressed` para el estado (no alternar el texto).
- **Menús desplegables de un botón**: patrón disclosure (`aria-expanded`/`aria-controls`, sin `role="menu"` mientras no haya navegación con flechas); Esc cierra y regresa el foco.
- **Labels**: siempre `<FieldLabel html-for text />`, nunca `<label>` a mano; campo obligatorio marca `required` (asterisco `text-danger`).
- **Listas repetibles en un form**: tabla compacta (encabezado una vez, fila delgada por registro), labels `sm:sr-only` desde `sm`, botón "+ Agregar" y quitar por fila (deshabilitado si solo queda 1).
- **Tablas de listado**: clase compartida `.data-table` (`@layer components`), nunca CSS de tabla duplicado con `:deep()`. Tablas anidadas dentro de una expansión llevan su propia clase.
- **Tablas agrupadas con expansión** (patrón `InventoryView.vue`): `DataTable` con row-expansion nativo vía slots, agrupación con helper JS puro cuando depende de reglas de negocio; lo resumible va como badge en la fila padre.
- **Listados simples con filtros rápidos** (`QuotesView.vue`): botones de filtro rápido (`QuickFilter.vue`) en vez de inputs de fecha libres; filtro que debe persistir vive en la URL (`router.replace`) y el store guarda el último query para que "Volver" lo respete. Toda la fila abre el detalle.
- **Vista de detalle de documento**: header con folio/status/fecha, card de datos generales, card de líneas con totales, acciones a la derecha (ocultas, no deshabilitadas, cuando no aplican por status). Acción destructiva separada por divisor; "Volver" en línea a la izquierda del título.
- **Confirmación destructiva**: `ConfirmDialog` unstyled con slot `#container`; también para salir de un form con cambios sin guardar (`onBeforeRouteLeave`, y `onBeforeRouteUpdate` si el modo cambia por query en la misma ruta).
- **Toast de éxito**: `useNotify()` → `AppToast.vue` único, montado en `App.vue`. Nunca para errores.
- **Borrado que el backend puede bloquear**: consultar `delete-summary` antes de confirmar; si `blocked_message`, el diálogo solo informa (botón "Entendido"), sin ofrecer una confirmación que fallaría.
- **Documentos imprimibles/compartibles**: PDF server-side (uno por tipo de documento — `QuotePdfGenerator`, `ComprobantePdfGenerator`), no `window.print()`. Botón "Compartir" con Web Share API, oculto (no deshabilitado) si el navegador no lo soporta.

## Checklist rápido antes de dar por terminada una tarea

- Tokens de color/tipografía del sistema de diseño (no hex/px sueltos), marca INNOVA visible correcta, `rem` + mobile-first, nada por debajo de `text-xs`.
- Commit en Conventional Commits + gitmoji, en inglés.
- Sin librería nueva, Repository Pattern, roles/permisos o borrado que no se haya pedido explícitamente; ningún módulo nuevo a medias dentro del trabajo de otro.
- Un hallazgo nuevo se resolvió en paso aparte o se anotó en `PROJECT.md`, no se coló en el paso en curso.
- `localhost` (no `127.0.0.1`) en cualquier URL de config.
- **Backend**: tablas/columnas en inglés; catálogos en tabla propia; Controller/Request en la subcarpeta de su módulo; IDs generados por el sistema excluidos del Form Request; todo campo nuevo validado en Store Y Update; normalización en `prepareForValidation()`; validación dependiente del modelo bindeado en el controlador, no en el Form Request; folios server-side con secuencia propia; conversión entre documentos reusa el endpoint de creación normal; línea cobrada/descontada en unidad física (vía `isSoldByPiece()`, nunca comparando `unit_type` a mano); relación anidada nueva usa `whenLoaded`; documento histórico carga con `withTrashed()`; guard reusable expresado como "rechazar todo lo que no sea X"; campo que determina forma de dependientes bloquea su cambio si ya hay dependientes; catálogo con soft delete tiene `withTrashed()`/`withoutTrashed()` donde corresponde.
- **Frontend**: componente de acción puntual generalizado por props en vez de duplicado; mutaciones in-place; `ConfirmDialog` para destructivo; `.data-table` en listados nuevos, nada depende de clases `p-*`; guard del backend con contraparte de UX sin duplicar la validación real; componente compartido en `widgets/`, uno de un solo módulo en `views/<módulo>/components/`; componente >500 líneas dividido en un paso de refactor aparte; fetch con filtro variable sin gate de `initialized`; nombre interno de ruta en inglés sin mezclar con otro prefijo.
