# PAVH

## Visión

PAVH es una aplicación web tipo **dashboard / panel administrativo**, en desarrollo temprano. Está construida como dos repositorios independientes que se comunican vía API REST + cookies de sesión.

## Arquitectura

```
pavh-backend/    Laravel 11 · API REST pura · Sanctum (SPA cookie-based auth) · MySQL
pavh-frontend/   Vue 3 + Vite · Pinia · Vue Router · Tailwind CSS · Axios
```

- **Auth**: Laravel Sanctum en modo SPA (cookies HttpOnly, no tokens en localStorage). Elegido sobre Passport por menor complejidad y por ser el patrón recomendado para un SPA propio (no API pública de terceros).
- **Entorno**: instalación nativa, sin Docker. `php artisan serve` (backend, :8000) + `npm run dev` (frontend, :5173).
- **Producción (plan)**: backend y frontend bajo el mismo dominio, con nginx como reverse proxy y el backend expuesto bajo `/api`. Esto evita problemas de cookies cross-site que sí aparecen en desarrollo con dominios distintos (por eso no se usa Laravel Valet por ahora — genera dominios `.test` que rompen `SameSite=Lax`).

## Estado actual

### Backend — ✅ completo y verificado
- Sanctum instalado (`php artisan install:api`), `statefulApi()` habilitado
- Endpoints: `login`, `logout`, `user` en `routes/api.php`
- CORS y dominios stateful configurados para `localhost:5173`
- Flujo completo verificado con curl y con `tests/Feature/AuthTest.php`
- Repo Git con commits limpios, ya en remoto

### Frontend — 🚧 en progreso
- Scaffold Vite + Vue 3 + Pinia + Vue Router + Tailwind (vía `@tailwindcss/vite`)
- `src/lib/axios.js`: instancia con `withCredentials: true` y `withXSRFToken: true`
- `src/stores/auth.js`: acciones `login` / `logout` / `fetchUser`, con flag `initialized` para no re-fetchear el usuario en cada navegación
- `src/router/index.js`: guard `beforeEach` que hidrata sesión y redirige a `/login` si no hay sesión
- `src/layouts/AppLayout.vue` y `AuthLayout.vue`
- `src/views/login/Login.vue`: formulario funcional, con validación, toggle de password, estados de error
- **Login funcionando de punta a punta** (localhost:5173 → localhost:8000)
- Pendiente: `HomeView.vue` es placeholder tanto en `/` como en `/dashboard` — falta el dashboard real
- Pendiente limpieza: sobra scaffold sin usar (`HelloWorld.vue`, `TheWelcome.vue`, `WelcomeItem.vue`, `icons/`, `stores/counter.js`, `AboutView.vue`)

## Decisiones clave y su razón

| Decisión | Razón |
|---|---|
| Sanctum sobre Passport | SPA propio, no necesita OAuth2 completo; cookies HttpOnly evitan XSS de localStorage |
| Sin Docker | Desarrollo nativo, menor fricción para el flujo actual |
| Sin Valet (por ahora) | Dominios `.test` rompen cookies `SameSite=Lax` frente a `localhost:5173` |
| `localhost` en vez de `127.0.0.1` | Son orígenes distintos para el navegador; debe coincidir con `CORS` y `SANCTUM_STATEFUL_DOMAINS` |
| Roles/permisos diferidos | Se definirá cuando el dashboard base esté funcional |
| Librería de componentes UI diferida | Se evalúa más adelante (shadcn-vue / Headless UI / PrimeVue); por ahora Tailwind puro |

## Dominio del negocio

PAVH es para un negocio de **venta de pisos y materiales de construcción**. El cliente actualmente hace notas de venta, cotizaciones y ventas a mano — el objetivo del sistema es modernizar y digitalizar ese flujo completo.

## Módulos

### 1. Inventario
- Registro de productos (pisos y materiales) con cantidad actual
- Altas y bajas de stock
- Alertas de stock bajo (umbral por producto)
- Dashboard de ventas por producto
- **Unidad de medida por producto**: no es única en todo el catálogo — algunos productos se manejan por pieza/caja (unidades enteras) y otros por m² u otra medida fraccionable. El modelo de producto debe soportar ambos tipos de unidad, definido a nivel producto individual, no global.

### 2. Cotizaciones
- Generar cotización seleccionando productos del catálogo de Inventario
- Imprimir cotización en tamaño carta/media carta
- **Se puede convertir directamente en una Venta (POS) sin recapturar datos** — la cotización es, en esencia, un borrador de venta. Esto implica que Cotización y Venta deben compartir la misma estructura de líneas de producto/cantidad/precio, y que una Venta puede tener un origen: "directa" o "desde cotización".

### 3. Punto de Venta (POS)
- Registrar ventas, ya sea directas o convertidas desde una cotización existente
- Descontar stock de Inventario automáticamente al concretar la venta
- Imprimir nota de venta en **tamaño carta/media carta** (no ticket térmico — esto descarta impresoras térmicas de 58mm/80mm como requisito, se resuelve con impresión estándar/PDF)

### Implicaciones técnicas a resolver cuando se construya cada módulo
- Modelo de `Producto` necesita un campo de tipo de unidad (pieza/caja vs. m² u otra medida) y posiblemente factores de conversión (ej. m² por caja)
- `Cotizacion` y `Venta` comparten estructura de líneas — evaluar si `Venta` es una entidad separada con referencia opcional a `Cotizacion`, o si `Cotizacion` es un estado de `Venta` (pendiente / convertida)
- Impresión: generar PDF carta/media carta (Laravel + librería PDF, ej. dompdf) — pendiente de decidir en detalle cuando se llegue a este módulo

## Roadmap

1. **Sistema de diseño** — definido en `AGENT.md` (paleta, tipografía, layout)
2. Dashboard real (reemplazar `HomeView.vue` placeholder)
3. Limpieza de scaffold sin usar
4. Navegación principal (sidebar + topbar) sobre `AppLayout.vue`
5. Módulo de Inventario (base del resto: catálogo de productos, stock)
6. Módulo de Cotizaciones (depende del catálogo de Inventario)
7. Módulo de Punto de Venta (depende de Cotizaciones e Inventario)
8. Roles y permisos
9. Selección de librería de componentes (si se decide adoptar una)

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
```
