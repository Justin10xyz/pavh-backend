<?php

namespace App\Models;

use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Product> $products
 * @property-read int|null $products_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UnitType newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UnitType newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UnitType query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UnitType whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UnitType whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UnitType whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UnitType whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['name'])]
class UnitType extends Model
{
    use SerializesDatesInAppTimezone;

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
