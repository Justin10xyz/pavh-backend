# AGENT.md — Reglas para IA trabajando en PAVH
 
Este documento es para cualquier agente (Claude Code, Cursor, etc.) que trabaje en `pavh-backend` o `pavh-frontend`. Léelo antes de generar código. El objetivo es que cualquier sesión nueva produzca resultados consistentes con las anteriores.
 
## Reglas generales de trabajo
 
- **Pedir pasos acotados, no tareas abiertas.** Justin prefiere prompts como "crea la instancia de axios" en vez de "conecta todo el frontend con el backend". Si te piden una tarea grande, divídela en pasos nombrados y secuenciales antes de escribir código. Si un paso resulta muy grande, divídelo también (ej. crear vs. editar en pasos separados).
- **Backend antes que frontend** cuando haya dependencia entre ambos — evita debug en capas cruzadas.
- **Empezar por lo visible.** Orden de construcción de frontend: UI visible → router → capa de config/servicios → estado (Pinia) → guards.
- **No asumas librerías nuevas.** No agregues una librería sin que se pida explícitamente, salvo las ya adoptadas (PrimeVue 4 unstyled para tablas/diálogos).
- **No implementes roles/permisos** todavía, aunque el código lo sugiera como "next step" obvio.
- **No implementes borrado si no se pidió explícitamente.** Varios módulos evitaron a propósito el borrado hasta un paso dedicado (ej. CRUD de producto sin eliminar hasta CRUD de variante) — no lo adelantes solo porque "tiene sentido" agregarlo.
- Commits en inglés, Conventional Commits + gitmoji (ver `PROJECT.md` para la sintaxis y ejemplos).

## Entorno — cosas que rompen si no se respetan
 
- Usar **`localhost`**, nunca `127.0.0.1` (son orígenes distintos para el navegador y rompen CORS/Sanctum).
- No usar Laravel Valet por ahora (dominios `.test` rompen cookies `SameSite=Lax`).
- Antes de cualquier request que modifique estado en Sanctum (`POST`/`PUT`/`DELETE`), debe existir un `GET /sanctum/csrf-cookie` previo — si no, 419.
- `EnsureFrontendRequestsAreStateful` requiere el header `Origin`. En tests PHPUnit: `$this->withHeader('Origin', 'http://localhost:5173')`.
- `VITE_API_URL` en el frontend **no** lleva `/api` al final (el store ya arma `/api/login`, `/sanctum/csrf-cookie`, etc.).
- Logout debe usar `Auth::guard('web')->logout()` — el guard `sanctum` (RequestGuard) no tiene método `logout()`.
- Al crear un registro cuya tabla tiene columnas con `default` a nivel MySQL (ej. `stock_boxes` default 0), Eloquent no refresca el modelo automáticamente tras el `INSERT` — el atributo puede llegar `null` en la respuesta aunque la BD ya tenga el default aplicado. Llamar `->refresh()` después de `create()` en esos casos.

## Convenciones de código (backend)

- **Nombres de tablas y columnas en inglés**, siempre — aunque el dominio de negocio y las conversaciones sean en español (ej. `products`, `product_variants`, `price_per_box`, no `productos`/`precio_caja`). Los valores de negocio visibles al usuario (labels en frontend, contenido de cotizaciones) sí pueden ir en español; el schema no.
- **Sin Repository Pattern.** Decisión explícita y deliberada — no agregar esta capa aunque parezca "buena práctica" de proyectos anteriores. Usar en su lugar:
  - **Query Scopes** nativos de Eloquent para filtros reusables (`scopeLowStock()`, `scopeByCategory()`, etc.) definidos directamente en el modelo.
  - **Form Requests** dedicados para toda validación de entrada — nunca validar inline en el controlador.
  - **API Resources** para dar forma a las respuestas JSON de forma consistente.
  - Controladores delgados: reciben el Form Request ya validado, delegan a scopes/modelo, devuelven un Resource.
- **Catálogos de valores (categorías, tipos de unidad, proveedores, comisiones, etc.) van en tablas propias**, no como `enum` de MySQL ni strings hardcodeados — permite agregar/renombrar valores con un insert/update, sin migración ni deploy. Cada catálogo expone un `GET /api/{catalogo}` simple (sin paginación, solo `id`+`name`) para poblar selects del frontend. Ejemplos: `categories`, `unit_types`, `suppliers`, `commission_categories`.
- **Códigos/identificadores generados por el sistema nunca se aceptan desde el cliente** en un Form Request — se generan server-side (ver ejemplo `VariantCodeGenerator`) y se excluyen explícitamente de los campos validados en Store/Update.
- **Acciones de negocio con efecto específico van en un endpoint dedicado**, no como parte de un update genérico — ej. ajuste de stock (`PATCH /product-variants/{id}/stock` con `quantity`+`type`) en vez de permitir editar `stock_boxes` directamente vía `PUT`. Facilita agregar trazabilidad/historial después sin rediseñar.
- **Validaciones de negocio que dependen del modelo bindeado por ruta van en el controlador (guard clause), no en el Form Request.** Ningún Form Request de este proyecto tiene acceso al modelo resuelto por route-model-binding. Si una regla necesita comparar contra el estado actual del modelo antes de mutar (ej. "no eliminar la última variante activa de un producto", "no dejar `stock_boxes` negativo al hacer `subtract`"), va como validación explícita al inicio del método del controlador, antes de la mutación — no se fuerza esa lógica dentro del Form Request solo por consistencia formal.
- **Todo campo nuevo en un modelo necesita regla de validación explícita en AMBOS Form Requests relevantes (Store y Update).** Laravel descarta silenciosamente del `validated()` cualquier campo sin regla definida, sin importar que el cliente sí lo envíe en el body — esto ya causó un bug real (`stock_boxes` nunca llegaba a `create()`/`update()` por faltar la regla). No asumir cobertura por la migración o el Resource; verificar explícitamente los dos Form Requests.
- **`SoftDeletes`** en cualquier modelo que pueda quedar referenciado desde otro módulo en el futuro (ya aplicado a `Product`/`ProductVariant`, pensando en Cotizaciones/Ventas). Si el modelo tiene un servicio que valida unicidad de algún campo (ej. `code`), esa validación debe usar `withTrashed()` para no reutilizar valores de registros borrados lógicamente.
- Si una tabla ya está migrada en un ambiente, cambios de estructura (como agregar `SoftDeletes`) van en una **migración nueva**, nunca editando una migración ya ejecutada.

## Convenciones de código (frontend)
 
- Componentes Vue: `PascalCase.vue`
- Composables/stores: `camelCase.js`, stores de Pinia con nombre descriptivo (`useAuthStore`, no `useStore`)
- Patrón ya establecido en `auth.js`: flag `initialized` para evitar refetch innecesario de datos en cada navegación — replicar este patrón en stores futuros que dependan de datos poco cambiantes (ya replicado en `inventory.js` y `catalogs.js`).
- **Mutaciones puntuales actualizan el store in-place, sin refetch completo.** Cuando una acción afecta un solo registro (ej. ajustar stock de una variante, eliminar una fila), actualiza solo ese registro dentro del array del store con la respuesta del backend — un refetch completo colapsaría filas/grupos que el usuario ya tenía expandidos en la UI. Patrón ya usado en `updateVariantStock()`.
- **Acciones con endpoint dedicado se ejecutan de inmediato al confirmarlas, no se difieren hasta el submit de un formulario contenedor** (ej. eliminar una variante existente dentro del form de edición de producto dispara el `DELETE` al momento, no espera al guardar el resto del form) — evita tener que implementar diffing de estado entre lo cargado y lo enviado.
- **Acciones destructivas requieren confirmación vía `ConfirmDialog` de PrimeVue**, nunca `confirm()` nativo del navegador — rompe la seriedad visual del sistema de diseño. Acciones no destructivas o fácilmente reversibles (ej. ajustar stock) no necesitan este paso extra.
- Layouts en `src/layouts/`, vistas en `src/views/<módulo>/`, componentes reutilizables en `src/components/`.
- Guards de router centralizados en `src/router/index.js`, no dispersos por vista.
- **Convención de nombres en inglés aplicada solo al código** (archivos, carpetas, componentes, nombre interno de ruta) — NO al contenido de negocio visible al usuario (labels, placeholders) ni a los paths de URL (esos se quedan en español, ej. `/inventario`, `/cotizaciones`).
- **Estructura fija de bloques en todo `.vue`** (existentes y futuros): siempre `template` → `script setup` → `style scoped`, en ese orden, sin excepción, aunque un bloque quede vacío.
- **Tablas de datos y diálogos: PrimeVue 4 (MIT, modo unstyled)** — nunca v5, por su cambio a licenciamiento PrimeUI (requiere licencia o muestra watermark). Estilos vía `:deep()` sobre elementos HTML nativos o markup propio en slots (ej. `#container`), no vía la prop `pt` salvo para piezas sin markup propio en modo unstyled (ej. el `mask`/overlay de un `Dialog`) — las keys internas del `pt` son poco confiables entre versiones.

```vue
<template>

</template>

<script setup>

</script>

<style scoped>

</style>
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
Importadas en `main.js` (pesos 400/500/600/700 de Inter, 400/600 de Source Serif 4).
 
### Paleta (variables CSS definidas en `@theme`, nunca hex sueltos en componentes)
 
| Token | Hex | Uso |
|---|---|---|
| `primary` | `#14293D` | Sidebar, headers, botones primarios, texto sobre fondo claro en marca |
| `primary-dark` | `#0B1A29` | Hover/active de elementos `primary` |
| `accent` | `#A67C3D` | Focus rings, indicador de sección/nav activa. Uso **limitado** — no decorativo, no botones grandes de este color |
| `bg` | `#F7F8FA` | Fondo general de la app |
| `surface` | `#FFFFFF` | Cards, modales, inputs |
| `border` | `#E4E7EC` | Bordes de cards, tablas, inputs |
| `text` | `#1D2939` | Texto principal |
| `text-muted` | `#667085` | Texto secundario, placeholders, labels |
| `success` | `#2F6844` | Confirmaciones, estados positivos |
| `danger` | `#A13D3D` | Errores, estados destructivos |
 
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

- **Forms** (patrón establecido en `Login.vue`, replicado en `ProductFormView.vue`): card centrada o de ancho completo según contexto, inputs con ícono a la izquierda cuando aplica, botón primario de color sólido (sin gradientes), estado de error inline (no toast para errores de validación de campo).
- **Listas repetibles dentro de un form** (patrón de colores en `ProductFormView.vue`): campos compartidos se capturan una sola vez; el campo que varía (ej. color) se captura como lista repetible con botón "+ Agregar" y botón de quitar por fila (deshabilitado si solo queda 1 fila).
- **Tablas agrupadas con expansión** (patrón de `InventoryView.vue`): PrimeVue `DataTable` con row-expansion nativo vía slots (`#body`/`#expansion`), agrupación visual hecha con un helper de JS puro (no de PrimeVue) cuando el agrupamiento depende de reglas de negocio específicas (ver `groupVariants.js`).
- **Confirmación de acciones destructivas**: `ConfirmDialog` de PrimeVue unstyled, con markup propio vía slot `#container` y los tokens de color del sistema de diseño.
- **Diálogos de acción puntual** (ej. ajuste de stock): `Dialog` de PrimeVue unstyled, mismo criterio visual que `ConfirmDialog`.
 
## Checklist antes de dar por terminada una tarea
 
- [ ] ¿Usa los tokens de color definidos arriba (no hex sueltos)?
- [ ] ¿Usa Inter para UI y Source Serif 4 solo en títulos de página?
- [ ] ¿Sigue el patrón de layout (sidebar/topbar/cards sin sombra pesada)?
- [ ] ¿El commit sigue Conventional Commits + gitmoji en inglés?
- [ ] ¿No introduce una librería de UI nueva, Repository Pattern, o roles/permisos sin que se haya pedido?
- [ ] ¿No introduce borrado ni ninguna otra acción destructiva sin que se haya pedido explícitamente?
- [ ] ¿Usa `localhost` (no `127.0.0.1`) en cualquier URL de config?
- [ ] (Backend) ¿Tablas/columnas nuevas están en inglés? ¿Catálogos de valores van en tabla propia, no hardcodeados?
- [ ] (Backend) ¿Los identificadores generados por el sistema quedan excluidos de los Form Requests de entrada?
- [ ] (Backend) ¿Todo campo nuevo tiene regla de validación en AMBOS Form Requests (Store y Update)?
- [ ] (Backend) ¿Las validaciones que dependen del modelo bindeado por ruta están en el controlador, no forzadas dentro del Form Request?
- [ ] (Frontend) ¿Las mutaciones puntuales actualizan el store in-place en vez de refetch completo?
- [ ] (Frontend) ¿Las acciones destructivas usan `ConfirmDialog` de PrimeVue, no `confirm()` nativo?