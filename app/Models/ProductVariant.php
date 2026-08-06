<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_id',
    'code',
    'supplier_code',
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
}
