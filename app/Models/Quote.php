<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'folio',
    'customer_id',
    'quote_status_id',
    'notes',
    'subtotal',
    'total',
    'created_by',
])]
class Quote extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    // withTrashed: el documento debe seguir mostrando (y reimprimiendo) a su
    // cliente aunque el cliente se haya dado de baja después.
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function quoteStatus(): BelongsTo
    {
        return $this->belongsTo(QuoteStatus::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class);
    }

    // Venta en la que se convirtió (sales.quote_id). Solo existe en estado
    // Convertida: una cotización se convierte una sola vez.
    public function sale(): HasOne
    {
        return $this->hasOne(Sale::class);
    }

    /**
     * Solo una cotización en Borrador puede convertirse a venta. Cualquier
     * otro estado (incluidos los que se agreguen después al catálogo) se
     * rechaza; devuelve el mensaje de rechazo, o null si se puede convertir.
     */
    public function conversionBlockedMessage(): ?string
    {
        return match ($this->quoteStatus->name) {
            'Borrador' => null,
            'Convertida' => 'Esta cotización ya fue convertida a venta.',
            'Cancelada' => 'Esta cotización está cancelada y no se puede convertir a venta.',
            default => "Esta cotización está en estado {$this->quoteStatus->name} y no se puede convertir a venta.",
        };
    }

    /**
     * Mismo criterio que conversionBlockedMessage(): solo Borrador se puede
     * cancelar y cualquier otro estado se rechaza.
     */
    public function cancellationBlockedMessage(): ?string
    {
        return match ($this->quoteStatus->name) {
            'Borrador' => null,
            'Convertida' => 'Esta cotización ya fue convertida a venta y no se puede cancelar.',
            'Cancelada' => 'Esta cotización ya está cancelada.',
            default => "Esta cotización está en estado {$this->quoteStatus->name} y no se puede cancelar.",
        };
    }

    // customer_id nulo no filtra (igual que dateRange con límites nulos). No
    // excluye clientes dados de baja: su historial se sigue pudiendo consultar.
    public function scopeForCustomer(Builder $query, ?int $customerId): Builder
    {
        return $query->when($customerId, fn (Builder $query) => $query->where('customer_id', $customerId));
    }
}
