<?php

namespace App\Models;

use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name'])]
class UnitType extends Model
{
    use SerializesDatesInAppTimezone;

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
