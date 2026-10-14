<?php

namespace App\Models;

use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name'])]
class QuoteStatus extends Model
{
    use SerializesDatesInAppTimezone;

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }
}
