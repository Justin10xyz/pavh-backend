<?php

namespace App\Models;

use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $product_id
 * @property string $code
 * @property string|null $supplier_code
 * @property int|null $commission_category_id
 * @property string|null $pei
 * @property int|null $ett
 * @property string $color
 * @property string $size
 * @property numeric|null $price_per_m2
 * @property numeric|null $price_per_box
 * @property int|null $pieces_per_box
 * @property numeric|null $m2_per_box
 * @property numeric|null $kilos_per_box
 * @property int|null $boxes_per_pallet
 * @property int|null $stock_boxes
 * @property int|null $minimum_stock
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Models\CommissionCategory|null $commissionCategory
 * @property-read \App\Models\Product|null $product
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\StockMovement> $stockMovements
 * @property-read int|null $stock_movements_count
 * @method static Builder<static>|ProductVariant byCategory(int $categoryId)
 * @method static Builder<static>|ProductVariant lowStock()
 * @method static Builder<static>|ProductVariant newModelQuery()
 * @method static Builder<static>|ProductVariant newQuery()
 * @method static Builder<static>|ProductVariant onlyTrashed()
 * @method static Builder<static>|ProductVariant query()
 * @method static Builder<static>|ProductVariant whereBoxesPerPallet($value)
 * @method static Builder<static>|ProductVariant whereCode($value)
 * @method static Builder<static>|ProductVariant whereColor($value)
 * @method static Builder<static>|ProductVariant whereCommissionCategoryId($value)
 * @method static Builder<static>|ProductVariant whereCreatedAt($value)
 * @method static Builder<static>|ProductVariant whereDeletedAt($value)
 * @method static Builder<static>|ProductVariant whereEtt($value)
 * @method static Builder<static>|ProductVariant whereId($value)
 * @method static Builder<static>|ProductVariant whereKilosPerBox($value)
 * @method static Builder<static>|ProductVariant whereM2PerBox($value)
 * @method static Builder<static>|ProductVariant whereMinimumStock($value)
 * @method static Builder<static>|ProductVariant wherePei($value)
 * @method static Builder<static>|ProductVariant wherePiecesPerBox($value)
 * @method static Builder<static>|ProductVariant wherePricePerBox($value)
 * @method static Builder<static>|ProductVariant wherePricePerM2($value)
 * @method static Builder<static>|ProductVariant whereProductId($value)
 * @method static Builder<static>|ProductVariant whereSize($value)
 * @method static Builder<static>|ProductVariant whereStockBoxes($value)
 * @method static Builder<static>|ProductVariant whereSupplierCode($value)
 * @method static Builder<static>|ProductVariant whereUpdatedAt($value)
 * @method static Builder<static>|ProductVariant withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|ProductVariant withoutTrashed()
 * @mixin \Eloquent
 */
#[Fillable([
    'product_id',
    'code',
    'supplier_code',
    'commission_category_id',
    'pei',
    'ett',
    'color',
    'size',
    'price_per_m2',
    'price_per_box',
    'pieces_per_box',
    'm2_per_box',
    'kilos_per_box',
    'boxes_per_pallet',
    'stock_boxes',
    'minimum_stock',
])]
class ProductVariant extends Model
{
    use SerializesDatesInAppTimezone, SoftDeletes;

    /**
     * Catálogo cerrado de PEI: romano simple, o rango de dos niveles
     * consecutivos tal como lo publica el proveedor (ej. "III/IV"). Mismo orden
     * que PEI_OPTIONS en el frontend.
     */
    public const PEI_VALUES = ['I', 'I/II', 'II', 'II/III', 'III', 'III/IV', 'IV', 'IV/V', 'V'];

    protected function casts(): array
    {
        return [
            'price_per_m2' => 'decimal:2',
            'price_per_box' => 'decimal:2',
            'm2_per_box' => 'decimal:3',
            'kilos_per_box' => 'decimal:2',
            'stock_boxes' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function commissionCategory(): BelongsTo
    {
        return $this->belongsTo(CommissionCategory::class);
    }

    /**
     * Sin seguimiento de stock (stock_boxes null) nunca es "bajo stock",
     * aunque tenga mínimo configurado.
     */
    public function scopeLowStock(Builder $query): Builder
    {
        return $query->whereNotNull('stock_boxes')
            ->whereNotNull('minimum_stock')
            ->whereColumn('stock_boxes', '<=', 'minimum_stock');
    }

    public function scopeByCategory(Builder $query, int $categoryId): Builder
    {
        return $query->whereHas('product', function (Builder $query) use ($categoryId) {
            $query->where('category_id', $categoryId);
        });
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * stock_boxes null significa que la variante no lleva seguimiento de
     * stock: no bloquea ventas, no genera alertas ni movimientos.
     */
    public function tracksStock(): bool
    {
        return $this->stock_boxes !== null;
    }

    public function hasSufficientStock(int $boxes): bool
    {
        return ! $this->tracksStock() || $boxes <= $this->stock_boxes;
    }

    public function isLowStock(): bool
    {
        return $this->tracksStock()
            && $this->minimum_stock !== null
            && $this->stock_boxes <= $this->minimum_stock;
    }

    /**
     * Pisos con unit_type "pieza" (ej. mosaicos/decorados): `boxes` en la
     * línea cuenta piezas, se cobran a price_per_box y no se derivan m².
     */
    public function isSoldByPiece(): bool
    {
        return $this->currentProduct()->isSoldByPiece();
    }

    /**
     * Guard de PUT /product-variants/{id} (depende de esta variante, por eso
     * no vive en UpdateProductVariantRequest):
     * - No se puede mover a un producto con otra unidad de venta: precios y
     *   atributos dejarían de tener sentido a medio editar.
     * - En un producto por pieza no se aceptan price_per_m2, m2_per_box, pei
     *   ni ett con valor. price_per_box no se exige: la fila ya lo tiene.
     *
     * @param  array<string, mixed>  $data  Payload ya validado.
     */
    public function updateBlockedMessage(array $data): ?string
    {
        $currentProduct = $this->currentProduct();

        $targetProduct = isset($data['product_id'])
            ? Product::withTrashed()->findOrFail($data['product_id'])
            : $currentProduct;

        if ((int) $targetProduct->unit_type_id !== (int) $currentProduct->unit_type_id) {
            return 'No se puede mover una variante a un producto con una unidad de venta distinta.';
        }

        if (! $targetProduct->isSoldByPiece()) {
            return null;
        }

        $m2Attributes = array_filter(
            array_intersect_key($data, array_flip(['price_per_m2', 'm2_per_box', 'pei', 'ett'])),
            fn ($value) => $value !== null,
        );

        if ($m2Attributes === []) {
            return null;
        }

        return "La variante {$this->code} se vende por pieza: no lleva precio por m², m² por caja, PEI ni ETT.";
    }

    // withTrashed: líneas viejas (PDF) pueden apuntar a un producto ya dado de baja.
    private function currentProduct(): Product
    {
        $this->loadMissing(['product' => fn ($query) => $query->withTrashed()]);

        return $this->product;
    }

    /**
     * m² que cubren `$boxes` cajas completas. Las variantes se venden por caja:
     * esta es la cantidad que se guarda como `quantity` de la línea y sobre la
     * que se cobra (precio por m²). Null si la variante no tiene m2_per_box —
     * quien llama debe rechazar la línea, nunca asumir 1:1.
     */
    public function squareMetersForBoxes(int $boxes): ?float
    {
        if ($this->m2_per_box === null) {
            return null;
        }

        return round($boxes * (float) $this->m2_per_box, 3);
    }
}
