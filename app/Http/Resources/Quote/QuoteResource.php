<?php

namespace App\Http\Resources\Quote;

use App\Models\Quote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Quote
 */
class QuoteResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'folio' => $this->folio,
            'customer_id' => $this->customer_id,
            'customer' => $this->customer ? [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
                'email' => $this->customer->email,
            ] : null,
            'quote_status_id' => $this->quote_status_id,
            'status' => $this->quoteStatus->name,
            'notes' => $this->notes,
            'subtotal' => $this->subtotal,
            'discount' => $this->discount,
            'total' => $this->total,
            'down_payment' => $this->down_payment,
            'shipping_cost' => $this->shipping_cost,
            // Mismo formato de string con 2 decimales que los montos con cast decimal:2.
            'balance' => number_format($this->balance(), 2, '.', ''),
            'payment_terms' => $this->payment_terms,
            // Solo día: sin hora ni zona, para que no se recorra al serializar.
            'credit_due_date' => $this->credit_due_date?->toDateString(),
            // Un Resource no pasa por serializeDate(); mismo formato que SerializesDatesInAppTimezone.
            'paid_at' => $this->paid_at?->toIso8601String(),
            'paid_by' => $this->paid_by,
            'created_by' => $this->created_by,
            'items' => QuoteItemResource::collection($this->whenLoaded('items')),
            // Solo id+folio, para enlazar al comprobante desde el detalle. null
            // si la relación se cargó pero la cotización no se ha convertido.
            'comprobante' => $this->whenLoaded('comprobante', fn () => $this->comprobante ? [
                'id' => $this->comprobante->id,
                'folio' => $this->comprobante->folio,
            ] : null),
            // Un Resource no pasa por serializeDate(); mismo formato que SerializesDatesInAppTimezone.
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
