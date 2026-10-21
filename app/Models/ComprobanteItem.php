<?php

namespace App\Models;

use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $comprobante_id
 * @property int|null $product_variant_id
 * @property int|null $simple_product_id
 * @property int|null $boxes
 * @property numeric $quantity
 * @property numeric $unit_price
 * @property string $price_type
 * @property numeric $line_total
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Comprobante|null $comprobante
 * @property-read ProductVariant|null $productVariant
 * @property-read SimpleProduct|null $simpleProduct
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ComprobanteItem newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ComprobanteItem newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ComprobanteItem query()
 *
 * @mixin \Eloquent
 */
#[Fillable(['comprobante_id', 'product_variant_id', 'simple_product_id', 'boxes', 'quantity', 'unit_price', 'price_type', 'line_total'])]
class ComprobanteItem extends Model
{
    use SerializesDatesInAppTimezone;

    protected function casts(): array
    {
        return [
            'boxes' => 'integer',
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Comprobante, $this>
     */
    public function comprobante(): BelongsTo
    {
        return $this->belongsTo(Comprobante::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /**
     * @return BelongsTo<SimpleProduct, $this>
     */
    public function simpleProduct(): BelongsTo
    {
        return $this->belongsTo(SimpleProduct::class);
    }
}
