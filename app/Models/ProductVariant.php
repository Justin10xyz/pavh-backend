<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'price_per_m2' => 'decimal:2',
            'price_per_box' => 'decimal:2',
            'm2_per_box' => 'decimal:3',
            'kilos_per_box' => 'decimal:2',
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

    public function scopeLowStock(Builder $query): Builder
    {
        return $query->whereNotNull('minimum_stock')
            ->whereColumn('stock_boxes', '<=', 'minimum_stock');
    }

    public function scopeByCategory(Builder $query, int $categoryId): Builder
    {
        return $query->whereHas('product', function (Builder $query) use ($categoryId) {
            $query->where('category_id', $categoryId);
        });
    }
}
