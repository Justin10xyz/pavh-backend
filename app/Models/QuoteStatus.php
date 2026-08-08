<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name'])]
class QuoteStatus extends Model
{
    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }
}
