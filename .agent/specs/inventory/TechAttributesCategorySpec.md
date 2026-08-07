# Spec: Atributos técnicos y categoría de comisión (Inventario)

## Contexto

El catálogo real de proveedor (Interceramic, línea Creato) trae 3 atributos que hoy no capturamos:

- **PEI**: resistencia al desgaste/tráfico (escala I-V, a veces como rango ej. "III/IV").
- **ETT**: variación de tono y textura dentro de la caja (escala 1-4).
- **Categoría de comisión** (`Vo`, `N`, `A`): control interno del negocio para % de comisión/descuento. El cliente no tiene las descripciones ni los porcentajes todavía — solo confirma que son niveles de comisión.

**Nota sobre "Traf"**: no lo vamos a capturar como campo aparte. Por lo que vimos en las fichas técnicas, es una simplificación derivada del PEI (uso doméstico/comercial), no un dato independiente. Guardarlo aparte duplicaría información y crearía una fuente de verdad extra que mantener. Si más adelante el cliente confirma que sí es un dato distinto e independiente del PEI, se agrega después — no bloquea nada de este spec.

## Aclaración clave: NO hay subgrupos como entidad nueva

Este punto es importante porque cambia cómo se ve el catálogo, pero no cambia el modelo de datos:

- Cada **variante** sigue siendo única por `producto (línea) + color + medida`, exactamente como ya está.
- PEI, ETT y categoría de comisión **no son un nivel de agrupación nuevo** — son atributos de esa misma fila de `product_variants`, igual que `color` o `price_per_box`.
- El caso que mencionaste (mismo tamaño, dos colores, dos categorías distintas) simplemente significa que esas dos variantes —que ya eran filas separadas por tener colores distintos— ahora también difieren en `commission_category_id`. No hace falta una tabla intermedia de "grupos de tamaño" ni tocar la lógica de generación de `code` en `VariantCodeGenerator` — el código sigue siendo `[PREFIJO]-[LINEA]-[COLOR]-[MEDIDA]`, sin relación con la categoría de comisión.

En otras palabras: esto es un cambio **aditivo** (columnas nuevas), no estructural.

---

## Paso 1 — Migración: tabla `commission_categories`

Catálogo de valores propio, siguiendo la convención ya establecida (como `categories` y `unit_types`) — nunca hardcodear estos códigos.

```php
Schema::create('commission_categories', function (Blueprint $table) {
    $table->id();
    $table->string('code')->unique(); // Vo, N, A — tal cual, sin traducir significado
    $table->decimal('percentage', 5, 2)->nullable(); // pendiente hasta que el cliente lo confirme
    $table->text('notes')->nullable();
    $table->timestamps();
});
```

## Paso 2 — Modelo `CommissionCategory`

```php
class CommissionCategory extends Model
{
    protected $fillable = ['code', 'percentage', 'notes'];

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }
}
```

## Paso 3 — Seeder `CommissionCategorySeeder`

Sembrar únicamente los códigos conocidos, con `percentage` en `null`:

```php
foreach (['Vo', 'N', 'A'] as $code) {
    CommissionCategory::create(['code' => $code]);
}
```

## Paso 4 — Migración nueva: columnas en `product_variants`

**Importante**: como `product_variants` ya está migrada en el ambiente, esto va en una migración nueva (`add_tile_attributes_to_product_variants`), nunca editando las migraciones ya ejecutadas — mismo patrón que se usó para agregar `SoftDeletes`.

```php
Schema::table('product_variants', function (Blueprint $table) {
    $table->foreignId('commission_category_id')
        ->nullable()
        ->after('supplier_code')
        ->constrained('commission_categories')
        ->nullOnDelete();

    $table->string('pei', 10)->nullable()->after('commission_category_id'); // ej. "IV", "III/IV"
    $table->unsignedTinyInteger('ett')->nullable()->after('pei'); // 1-4
});
```

Todos nullable porque estos atributos son específicos de piso/azulejo — otras categorías de producto que el negocio catalogue a futuro (que no son piso) no necesariamente los tendrán.

## Paso 5 — Actualizar modelo `ProductVariant`

- Agregar `commission_category_id`, `pei`, `ett` a `$fillable`.
- Agregar relación:

```php
public function commissionCategory(): BelongsTo
{
    return $this->belongsTo(CommissionCategory::class);
}
```

## Paso 6 — Actualizar Form Requests

En `StoreProductVariantRequest` y `UpdateProductVariantRequest`, agregar reglas:

```php
'commission_category_id' => ['nullable', 'exists:commission_categories,id'],
'pei' => ['nullable', 'string', 'max:10'],
'ett' => ['nullable', 'integer', 'between:1,4'],
```

Sin cambios en `AdjustStockRequest` (no aplica ahí).

## Paso 7 — Actualizar `ProductVariantResource`

Agregar al arreglo de respuesta:

```php
'pei' => $this->pei,
'ett' => $this->ett,
'commission_category' => $this->whenLoaded('commissionCategory', fn () => [
    'id' => $this->commissionCategory->id,
    'code' => $this->commissionCategory->code,
]),
```

## Paso 8 — Endpoint mínimo para listar categorías de comisión

Solo lectura por ahora (el cliente no pidió administrarlas desde la UI, y no tiene los porcentajes definidos todavía — no hay que anticipar CRUD que no se ha pedido):

- `GET /api/commission-categories` bajo `auth:sanctum` → devuelve lista simple vía Resource nuevo `CommissionCategoryResource` (`id`, `code`, `percentage`, `notes`). Sirve para poblar un `<select>` en el frontend cuando se cree/edite una variante.

No se agrega store/update/delete en este spec — se agrega cuando el cliente confirme que necesita mantenerlas desde la app en vez de por seeder/DB directo.

## Paso 9 — Actualizar seeder de Creato

Completar los datos reales de PEI/ETT/categoría de comisión por variante, usando lo que ya se transcribió del catálogo (imágenes). Donde falte el dato (categoría de comisión no visible para alguna variante), dejar `commission_category_id` en `null` en vez de adivinar.

## Checklist de verificación

- [ ] Migraciones corren limpio (`php artisan migrate`) y no tocan migraciones ya ejecutadas.
- [ ] `commission_category_id`, `pei`, `ett` son nullable.
- [ ] `VariantCodeGenerator` sigue sin cambios — no depende de categoría de comisión.
- [ ] Form Requests validan los 3 campos nuevos como opcionales.
- [ ] `ProductVariantResource` expone `pei`, `ett`, `commission_category`.
- [ ] Nuevo endpoint `GET /api/commission-categories` responde bajo `auth:sanctum`.
- [ ] Seeder de `commission_categories` crea Vo/N/A con `percentage: null`.
- [ ] Tests existentes (9/9) siguen pasando + agregar test mínimo para el nuevo endpoint.
- [ ] Pint limpio.

## Abierto para después (no bloquea este spec)

- Porcentajes reales de cada categoría de comisión — pendiente de que el cliente los proporcione.
- Precio de dos niveles (lista/público) que se ve en el catálogo — hoy solo tienes un `price_per_box`/`price_per_m2`. Evaluar si Cotizaciones necesita ambos niveles antes de tocar esto.
- Confirmar si "Traf" resulta ser un dato independiente del PEI (poco probable, pero queda anotado).
