<?php

namespace App\Models;

use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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
        // withTrashed: líneas viejas (PDF) pueden apuntar a un producto ya dado de baja.
        $this->loadMissing([
            'product' => fn ($query) => $query->withTrashed(),
            'product.unitType',
        ]);

        return $this->product->unitType->name === 'pieza';
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
