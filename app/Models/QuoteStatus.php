<?php

namespace App\Models;

use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Quote> $quotes
 * @property-read int|null $quotes_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|QuoteStatus newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|QuoteStatus newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|QuoteStatus query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|QuoteStatus whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|QuoteStatus whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|QuoteStatus whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|QuoteStatus whereUpdatedAt($value)
 * @mixin \Eloquent
 */
#[Fillable(['name'])]
class QuoteStatus extends Model
{
    use SerializesDatesInAppTimezone;

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }
}
