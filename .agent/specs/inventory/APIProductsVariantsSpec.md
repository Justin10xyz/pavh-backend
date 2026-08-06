# Spec — Inventario: API de Productos y Variantes (Fase 2)

## Objetivo de esta fase

Exponer `Product` y `ProductVariant` vía API REST, protegida por la misma sesión de Sanctum ya funcionando, con controladores delgados apoyados en Query Scopes, Form Requests y API Resources — sin Repository Pattern ni otras capas que el proyecto no necesita todavía.

**Fuera de alcance en esta fase:** frontend del catálogo, historial de movimientos de stock (por ahora el ajuste de stock es directo, sin bitácora), importador de listas de precios.

## Decisiones de esta fase

- **Endpoints separados** para `products` y `product-variants` (no anidados), más un parámetro `?with=variants` en el índice de productos para cuando se necesite la vista agrupada.
- **Query Scopes** nativos de Eloquent para filtros reusables — no Repository Pattern.
- **Form Requests** para toda validación de entrada.
- **API Resources** para dar forma consistente a las respuestas JSON.
- **Código de variante nunca se recibe del cliente** — siempre se genera server-side con `VariantCodeGenerator` (ya construido en Fase 1). El campo `code` no forma parte del Form Request de creación.
- **Rutas protegidas** con el mismo middleware `auth:sanctum` que ya usa `/api/user` — es un panel administrativo, no hay endpoints públicos aquí.
- **`SoftDeletes` en `Product` y `ProductVariant`** — un producto o variante puede quedar referenciado desde una cotización o venta futura; borrarlo físicamente rompería esa referencia. El `DELETE` de esta fase se vuelve borrado lógico (`deleted_at`), transparente para los listados normales gracias al scope global que Eloquent aplica automáticamente. Como las tablas de Fase 1 ya están migradas, esto se agrega con una migración nueva (`add_soft_deletes_to_products_and_product_variants`), no editando las migraciones existentes.

## Query Scopes (en `ProductVariant`)

| Scope | Uso | Lógica |
|---|---|---|
| `scopeLowStock` | `ProductVariant::lowStock()->get()` | `stock_boxes <= minimum_stock`, excluyendo `minimum_stock` nulo |
| `scopeByCategory($categoryId)` | `ProductVariant::byCategory($id)->get()` | filtra por `product.category_id` vía `whereHas` |

## Form Requests

| Request | Para | Campos validados |
|---|---|---|
| `StoreProductRequest` | `POST /products` | `name` (required), `supplier_id` (required, exists), `category_id` (required, exists), `unit_type_id` (required, exists), `purchase_unit` (required) |
| `UpdateProductRequest` | `PUT/PATCH /products/{id}` | mismos campos, todos `sometimes` |
| `StoreProductVariantRequest` | `POST /product-variants` | `product_id` (required, exists), `color` (required), `size` (required), `price_per_m2`, `price_per_box`, `pieces_per_box`, `m2_per_box`, `kilos_per_box`, `boxes_per_pallet`, `minimum_stock` — todos numéricos/nullable según la tabla de Fase 1. **Sin `code`** |
| `UpdateProductVariantRequest` | `PUT/PATCH /product-variants/{id}` | mismos campos que Store, todos `sometimes`, tampoco acepta `code` |
| `AdjustStockRequest` | `PATCH /product-variants/{id}/stock` | `quantity` (required, integer), `type` (required, in: `add`,`subtract`) |

## API Resources

**`ProductResource`**: `id`, `name`, `supplier` (nombre), `category` (nombre), `unit_type` (nombre), `purchase_unit`, y `variants` **solo si la relación viene cargada** (`whenLoaded`) — así el mismo resource sirve para el listado simple y para `?with=variants` sin duplicar código.

**`ProductVariantResource`**: `id`, `code`, `supplier_code`, `color`, `size`, `price_per_m2`, `price_per_box`, `pieces_per_box`, `m2_per_box`, `kilos_per_box`, `boxes_per_pallet`, `stock_boxes`, `minimum_stock`, y un campo calculado `low_stock` (booleano: `stock_boxes <= minimum_stock`) para que el frontend no tenga que recalcularlo.

## Endpoints

| Método | Ruta | Acción |
|---|---|---|
| GET | `/api/products` | index — acepta `?with=variants` |
| GET | `/api/products/{id}` | show |
| POST | `/api/products` | store |
| PUT/PATCH | `/api/products/{id}` | update |
| DELETE | `/api/products/{id}` | destroy — soft delete |
| GET | `/api/product-variants` | index — acepta `?low_stock=1`, `?category_id=` |
| GET | `/api/product-variants/{id}` | show |
| POST | `/api/product-variants` | store — genera `code` internamente vía `VariantCodeGenerator` |
| PUT/PATCH | `/api/product-variants/{id}` | update |
| DELETE | `/api/product-variants/{id}` | destroy — soft delete |
| PATCH | `/api/product-variants/{id}/stock` | adjustStock — suma o resta `quantity` a `stock_boxes` |

Todas bajo el grupo de rutas con `auth:sanctum`, junto a las rutas de auth ya existentes.

**Nota sobre unicidad y soft deletes:** `VariantCodeGenerator` debe validar unicidad de `code` incluyendo registros soft-deleted (`withTrashed()`), para que un código no se reutilice aunque su variante original esté "borrada" — evita confusión si esa variante vieja sigue referenciada en una cotización histórica.

## Pasos escoteados para Claude Code

1. Migración `add_soft_deletes_to_products_and_product_variants` (agrega `deleted_at` a ambas tablas) + trait `SoftDeletes` en los modelos `Product` y `ProductVariant`
2. Ajustar `VariantCodeGenerator` para validar unicidad con `withTrashed()`
3. Query Scopes `scopeLowStock` y `scopeByCategory` en `ProductVariant`
4. Form Requests: `StoreProductRequest`, `UpdateProductRequest`
5. Form Requests: `StoreProductVariantRequest`, `UpdateProductVariantRequest`, `AdjustStockRequest`
6. API Resources: `ProductResource`, `ProductVariantResource`
7. `ProductController` (index con `?with=variants`, show, store, update, destroy)
8. `ProductVariantController` (index con filtros, show, store — usando `VariantCodeGenerator`, update, destroy)
9. Método `adjustStock` en `ProductVariantController` + su ruta
10. Rutas en `routes/api.php`, agrupadas bajo `auth:sanctum`
11. Test de feature básico (crear producto → crear variante → verificar código generado → ajustar stock → verificar `low_stock` → soft delete → verificar que desaparece del listado pero sigue en BD)
12. Verificación manual con curl (recordando el paso previo de `csrf-cookie` antes de cualquier POST/PUT/PATCH/DELETE, y el header `Origin`)
