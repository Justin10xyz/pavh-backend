<?php

namespace App\Models;

use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $folio
 * @property int|null $quote_id
 * @property int|null $customer_id
 * @property numeric $subtotal
 * @property numeric $total
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Models\User|null $createdBy
 * @property-read \App\Models\Customer|null $customer
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SaleItem> $items
 * @property-read int|null $items_count
 * @property-read \App\Models\Quote|null $quote
 * @method static Builder<static>|Sale dateRange(?string $from, ?string $to)
 * @method static Builder<static>|Sale forCustomer(?int $customerId)
 * @method static Builder<static>|Sale newModelQuery()
 * @method static Builder<static>|Sale newQuery()
 * @method static Builder<static>|Sale onlyTrashed()
 * @method static Builder<static>|Sale query()
 * @method static Builder<static>|Sale whereCreatedAt($value)
 * @method static Builder<static>|Sale whereCreatedBy($value)
 * @method static Builder<static>|Sale whereCustomerId($value)
 * @method static Builder<static>|Sale whereDeletedAt($value)
 * @method static Builder<static>|Sale whereFolio($value)
 * @method static Builder<static>|Sale whereId($value)
 * @method static Builder<static>|Sale whereQuoteId($value)
 * @method static Builder<static>|Sale whereSubtotal($value)
 * @method static Builder<static>|Sale whereTotal($value)
 * @method static Builder<static>|Sale whereUpdatedAt($value)
 * @method static Builder<static>|Sale withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|Sale withoutTrashed()
 * @mixin \Eloquent
 */
#[Fillable([
    'folio',
    'quote_id',
    'customer_id',
    'subtotal',
    'total',
    'created_by',
])]
class Sale extends Model
{
    use SerializesDatesInAppTimezone, SoftDeletes;

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    // withTrashed: el documento debe seguir mostrando (y reimprimiendo) a su
    // cliente aunque el cliente se haya dado de baja después.
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    // Ambos límites son inclusivos y comparan solo la fecha (whereDate), así
    // que to=2026-10-07 incluye las ventas de todo ese día.
    public function scopeDateRange(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $query) => $query->whereDate('created_at', '>=', $from))
            ->when($to, fn (Builder $query) => $query->whereDate('created_at', '<=', $to));
    }

    // customer_id nulo no filtra (igual que dateRange con límites nulos). No
    // excluye clientes dados de baja: su historial se sigue pudiendo consultar.
    public function scopeForCustomer(Builder $query, ?int $customerId): Builder
    {
        return $query->when($customerId, fn (Builder $query) => $query->where('customer_id', $customerId));
    }
}
