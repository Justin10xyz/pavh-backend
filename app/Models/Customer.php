<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'phone', 'email'])]
class Customer extends Model
{
    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(\App\Models\Sale::class);
    }
}
