<?php

namespace App\Models;

use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Abono: pago parcial posterior de una cotización. Se resta de su saldo
 * (Quote::balance()) junto con el anticipo. Anular un abono es un soft
 * delete: deja de contar, pero se conserva el registro.
 *
 * @property int $id
 * @property int $quote_id
 * @property numeric $amount
 * @property Carbon $payment_date
 * @property string|null $notes
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Quote $quote
 * @property-read User|null $createdBy
 *
 * @method static Builder<static>|QuoteInstallment newModelQuery()
 * @method static Builder<static>|QuoteInstallment newQuery()
 * @method static Builder<static>|QuoteInstallment onlyTrashed()
 * @method static Builder<static>|QuoteInstallment query()
 * @method static Builder<static>|QuoteInstallment withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|QuoteInstallment withoutTrashed()
 *
 * @mixin \Eloquent
 */
#[Fillable([
    'quote_id',
    'amount',
    'payment_date',
    'notes',
    'created_by',
])]
class QuoteInstallment extends Model
{
    use SerializesDatesInAppTimezone, SoftDeletes;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            // Solo día, sin hora: se serializa como Y-m-d para que el cambio a
            // UTC/offset de serializeDate() no lo recorra al día anterior.
            'payment_date' => 'date:Y-m-d',
        ];
    }

    /**
     * withTrashed: el abono sigue apuntando a su cotización aunque esta se
     * haya dado de baja después.
     *
     * @return BelongsTo<Quote, $this>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
