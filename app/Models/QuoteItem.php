<?php

namespace App\Models;

use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $quote_id
 * @property int|null $product_variant_id
 * @property int|null $simple_product_id
 * @property int|null $boxes
 * @property numeric $quantity
 * @property numeric $unit_price
 * @property string $price_type
 * @property numeric $line_total
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ProductVariant|null $productVariant
 * @property-read Quote|null $quote
 * @property-read SimpleProduct|null $simpleProduct
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|QuoteItem newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|QuoteItem newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|QuoteItem query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|QuoteItem whereBoxes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|QuoteItem whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|QuoteItem whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|QuoteItem whereLineTotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|QuoteItem wherePriceType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|QuoteItem whereProductVariantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|QuoteItem whereQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|QuoteItem whereQuoteId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|QuoteItem whereSimpleProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|QuoteItem whereUnitPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|QuoteItem whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['quote_id', 'product_variant_id', 'simple_product_id', 'boxes', 'quantity', 'unit_price', 'price_type', 'line_total'])]
class QuoteItem extends Model
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

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function simpleProduct(): BelongsTo
    {
        return $this->belongsTo(SimpleProduct::class);
    }
}
