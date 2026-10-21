<?php

namespace App\Models;

use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $folio
 * @property int $quote_id
 * @property int|null $customer_id
 * @property Carbon $date
 * @property numeric $subtotal
 * @property numeric $total
 * @property string|null $total_in_words
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $createdBy
 * @property-read Customer|null $customer
 * @property-read Collection<int, ComprobanteItem> $items
 * @property-read int|null $items_count
 * @property-read Quote|null $quote
 *
 * @method static Builder<static>|Comprobante newModelQuery()
 * @method static Builder<static>|Comprobante newQuery()
 * @method static Builder<static>|Comprobante onlyTrashed()
 * @method static Builder<static>|Comprobante query()
 * @method static Builder<static>|Comprobante withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|Comprobante withoutTrashed()
 *
 * @mixin \Eloquent
 */
#[Fillable([
    'folio',
    'quote_id',
    'customer_id',
    'date',
    'subtotal',
    'total',
    'total_in_words',
    'created_by',
])]
class Comprobante extends Model
{
    use SerializesDatesInAppTimezone, SoftDeletes;

    protected function casts(): array
    {
        return [
            // Solo día, sin hora: se serializa como Y-m-d para que el cambio a
            // UTC/offset de serializeDate() no lo recorra al día anterior.
            'date' => 'date:Y-m-d',
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Quote, $this>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /**
     * withTrashed: el documento debe seguir mostrando (y reimprimiendo) a su
     * cliente aunque el cliente se haya dado de baja después.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<ComprobanteItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ComprobanteItem::class);
    }
}
