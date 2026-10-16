<?php

namespace App\Models;

use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
