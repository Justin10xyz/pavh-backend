<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
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
}
