<?php

namespace App\Models;

use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $code
 * @property string|null $name
 * @property int|null $tier
 * @property numeric|null $percentage
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ProductVariant> $variants
 * @property-read int|null $variants_count
 * @method static Builder<static>|CommissionCategory newModelQuery()
 * @method static Builder<static>|CommissionCategory newQuery()
 * @method static Builder<static>|CommissionCategory ordered()
 * @method static Builder<static>|CommissionCategory query()
 * @method static Builder<static>|CommissionCategory whereCode($value)
 * @method static Builder<static>|CommissionCategory whereCreatedAt($value)
 * @method static Builder<static>|CommissionCategory whereId($value)
 * @method static Builder<static>|CommissionCategory whereName($value)
 * @method static Builder<static>|CommissionCategory whereNotes($value)
 * @method static Builder<static>|CommissionCategory wherePercentage($value)
 * @method static Builder<static>|CommissionCategory whereTier($value)
 * @method static Builder<static>|CommissionCategory whereUpdatedAt($value)
 * @mixin \Eloquent
 */
#[Fillable(['code', 'name', 'tier', 'percentage', 'notes'])]
class CommissionCategory extends Model
{
    use SerializesDatesInAppTimezone;

    protected function casts(): array
    {
        return [
            'percentage' => 'decimal:2',
            'tier' => 'integer',
        ];
    }

    // De la que más comisión deja (tier 1) a la que menos; las que no tienen
    // tier van al final.
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByRaw('tier is null')->orderBy('tier')->orderBy('code');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }
}
