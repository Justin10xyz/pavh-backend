<?php

namespace App\Models;

use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $folio
 * @property int|null $customer_id
 * @property int $quote_status_id
 * @property string|null $notes
 * @property numeric $subtotal
 * @property numeric|null $discount
 * @property numeric|null $down_payment
 * @property numeric|null $shipping_cost
 * @property numeric $total
 * @property string $payment_terms
 * @property Carbon|null $credit_due_date
 * @property Carbon|null $paid_at
 * @property int|null $paid_by
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $createdBy
 * @property-read User|null $paidBy
 * @property-read Customer|null $customer
 * @property-read Collection<int, QuoteItem> $items
 * @property-read int|null $items_count
 * @property-read Collection<int, QuoteInstallment> $installments
 * @property-read numeric|null $installments_sum_amount
 * @property-read Comprobante|null $comprobante
 * @property-read QuoteStatus $quoteStatus
 *
 * @method static Builder<static>|Quote forCustomer(?int $customerId)
 * @method static Builder<static>|Quote newModelQuery()
 * @method static Builder<static>|Quote newQuery()
 * @method static Builder<static>|Quote onlyTrashed()
 * @method static Builder<static>|Quote query()
 * @method static Builder<static>|Quote whereCreatedAt($value)
 * @method static Builder<static>|Quote whereCreatedBy($value)
 * @method static Builder<static>|Quote whereCustomerId($value)
 * @method static Builder<static>|Quote whereDeletedAt($value)
 * @method static Builder<static>|Quote whereFolio($value)
 * @method static Builder<static>|Quote whereId($value)
 * @method static Builder<static>|Quote whereNotes($value)
 * @method static Builder<static>|Quote whereQuoteStatusId($value)
 * @method static Builder<static>|Quote whereSubtotal($value)
 * @method static Builder<static>|Quote whereTotal($value)
 * @method static Builder<static>|Quote whereUpdatedAt($value)
 * @method static Builder<static>|Quote withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|Quote withoutTrashed()
 *
 * @mixin \Eloquent
 */
#[Fillable([
    'folio',
    'customer_id',
    'quote_status_id',
    'notes',
    'subtotal',
    'discount',
    'down_payment',
    'shipping_cost',
    'total',
    'payment_terms',
    'credit_due_date',
    'paid_at',
    'paid_by',
    'created_by',
])]
class Quote extends Model
{
    use SerializesDatesInAppTimezone, SoftDeletes;

    /**
     * Valores de `payment_terms`: contado o crédito. Con crédito la cotización
     * lleva fecha de vencimiento (`credit_due_date`).
     */
    public const PAYMENT_TERMS = ['cash', 'credit'];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'down_payment' => 'decimal:2',
            'shipping_cost' => 'decimal:2',
            'total' => 'decimal:2',
            'credit_due_date' => 'date',
            'paid_at' => 'datetime',
        ];
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
     * @return BelongsTo<QuoteStatus, $this>
     */
    public function quoteStatus(): BelongsTo
    {
        return $this->belongsTo(QuoteStatus::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    /**
     * @return HasMany<QuoteItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class);
    }

    /**
     * Comprobante generado desde esta cotización (comprobantes.quote_id,
     * UNIQUE): a lo más uno por cotización.
     *
     * @return HasOne<Comprobante, $this>
     */
    public function comprobante(): HasOne
    {
        return $this->hasOne(Comprobante::class);
    }

    /**
     * Abonos vigentes (los anulados quedan fuera por el soft delete), del más
     * antiguo al más reciente.
     *
     * @return HasMany<QuoteInstallment, $this>
     */
    public function installments(): HasMany
    {
        return $this->hasMany(QuoteInstallment::class)->orderBy('payment_date')->orderBy('id');
    }

    /**
     * Solo una cotización en Borrador se puede cancelar; cualquier otro estado
     * (incluidos los que se agreguen después al catálogo) se rechaza. Devuelve
     * el mensaje de rechazo, o null si se puede cancelar.
     */
    public function cancellationBlockedMessage(): ?string
    {
        // Pagada: cancelar no regresa el stock que ya se descontó.
        if ($this->paid_at !== null) {
            return 'Esta cotización ya fue pagada y no se puede cancelar.';
        }

        // Abonos vigentes: el dinero recibido no se puede quedar en una cancelada.
        if ($this->installments()->exists()) {
            return 'Esta cotización tiene abonos registrados; anúlalos antes de cancelarla.';
        }

        return match ($this->quoteStatus->name) {
            'Borrador' => null,
            'Cancelada' => 'Esta cotización ya está cancelada.',
            default => "Esta cotización está en estado {$this->quoteStatus->name} y no se puede cancelar.",
        };
    }

    /**
     * Mismo criterio que cancellationBlockedMessage(): solo una cotización en
     * Borrador se puede marcar como pagada, y una sola vez (el pago descuenta
     * su stock).
     */
    public function markPaidBlockedMessage(): ?string
    {
        if ($this->paid_at !== null) {
            return 'Esta cotización ya fue marcada como pagada.';
        }

        $statusMessage = match ($this->quoteStatus->name) {
            'Borrador' => null,
            'Cancelada' => 'Esta cotización está cancelada y no se puede marcar como pagada.',
            default => "Esta cotización está en estado {$this->quoteStatus->name} y no se puede marcar como pagada.",
        };

        // Un Borrador viejo puede no tener cliente (antes era opcional).
        if ($statusMessage === null && $this->customer_id === null) {
            return 'Asigna un cliente al presupuesto antes de marcarlo como pagado.';
        }

        return $statusMessage;
    }

    /**
     * Todo presupuesto lleva cliente. Para un PUT: si `$data` (ya validado) no
     * trae customer_id se conserva el actual, que en un Borrador anterior a
     * esta regla puede ser null. Si lo trae, UpdateQuoteRequest ya rechazó el
     * null.
     *
     * @param  array<string, mixed>  $data
     */
    public function missingCustomerMessage(array $data): ?string
    {
        $customerId = array_key_exists('customer_id', $data) ? $data['customer_id'] : $this->customer_id;

        return $customerId === null ? __('validation.custom.quotes.customer_id.required') : null;
    }

    /**
     * Mismo criterio que markPaidBlockedMessage(): solo una cotización en
     * Borrador admite registrar o anular abonos. `$voiding` solo cambia la
     * redacción (registrar/anular).
     */
    public function installmentBlockedMessage(bool $voiding = false): ?string
    {
        $action = $voiding ? 'anular' : 'registrar';

        if ($this->paid_at !== null) {
            return "Esta cotización ya fue pagada y no se le pueden {$action} abonos.";
        }

        return match ($this->quoteStatus->name) {
            'Borrador' => null,
            'Cancelada' => "Esta cotización está cancelada y no se le pueden {$action} abonos.",
            default => "Esta cotización está en estado {$this->quoteStatus->name} y no se le pueden {$action} abonos.",
        };
    }

    /**
     * No se puede eliminar una cotización pagada (su stock ya se descontó), con
     * abonos vigentes, ni una que ya originó un comprobante: ese documento la
     * referencia. Cuenta también comprobantes dados de baja, que se siguen
     * pudiendo consultar con su cotización.
     */
    public function deletionBlockedMessage(): ?string
    {
        if ($this->paid_at !== null) {
            return 'Esta cotización ya fue pagada y no se puede eliminar.';
        }

        if ($this->installments()->exists()) {
            return 'Esta cotización tiene abonos registrados; anúlalos antes de eliminarla.';
        }

        if ($this->comprobante()->withTrashed()->exists()) {
            return 'Esta cotización ya tiene un comprobante y no se puede eliminar.';
        }

        return null;
    }

    /**
     * Solo una cotización pagada genera comprobante, y a lo más uno. Cuenta
     * también un comprobante dado de baja: el índice UNIQUE de
     * comprobantes.quote_id lo incluye, así que tampoco deja generar otro.
     */
    public function comprobanteConversionBlockedMessage(): ?string
    {
        if ($this->paid_at === null) {
            return 'Esta cotización no ha sido pagada y no se puede convertir a comprobante.';
        }

        if ($this->comprobante()->withTrashed()->exists()) {
            return 'Esta cotización ya tiene un comprobante.';
        }

        return null;
    }

    /**
     * Saldo pendiente: total (ya con descuento y envío) - anticipo - abonos.
     * Anticipo null (no aplica) cuenta como 0; los abonos anulados no cuentan.
     */
    public function balance(): float
    {
        return round((float) $this->total - (float) $this->down_payment - $this->installmentsTotal(), 2);
    }

    /**
     * Suma de los abonos vigentes, sin una consulta por cotización en un
     * listado: usa la relación si ya está cargada, o installments_sum_amount
     * si la consulta trajo withSum('installments', 'amount'). Solo si no hay
     * ninguno de los dos carga la relación (una consulta, y las siguientes
     * llamadas ya la reutilizan).
     */
    public function installmentsTotal(): float
    {
        if (! $this->relationLoaded('installments') && array_key_exists('installments_sum_amount', $this->attributes)) {
            return round((float) $this->attributes['installments_sum_amount'], 2);
        }

        $this->loadMissing('installments');

        return round((float) $this->installments->sum('amount'), 2);
    }

    /**
     * customer_id nulo no filtra (igual que dateRange con límites nulos). No
     * excluye clientes dados de baja: su historial se sigue pudiendo consultar.
     *
     * @param  Builder<Quote>  $query
     * @return Builder<Quote>
     */
    public function scopeForCustomer(Builder $query, ?int $customerId): Builder
    {
        return $query->when($customerId, fn (Builder $query) => $query->where('customer_id', $customerId));
    }
}
