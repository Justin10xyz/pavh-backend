<?php

namespace App\Http\Resources\Comprobante;

use App\Models\Comprobante;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Comprobante
 */
class ComprobanteResource extends JsonResource
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
            'quote_id' => $this->quote_id,
            // Solo id+folio, para enlazar a la cotización de origen.
            'quote' => $this->whenLoaded('quote', fn () => [
                'id' => $this->quote->id,
                'folio' => $this->quote->folio,
            ]),
            'customer_id' => $this->customer_id,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
                'email' => $this->customer->email,
            ] : null),
            // Solo día: sin hora ni zona, para que no se recorra al serializar.
            'date' => $this->date->toDateString(),
            'subtotal' => $this->subtotal,
            'total' => $this->total,
            'total_in_words' => $this->total_in_words,
            'created_by' => $this->created_by,
            'items' => ComprobanteItemResource::collection($this->whenLoaded('items')),
            // Un Resource no pasa por serializeDate(); mismo formato que SerializesDatesInAppTimezone.
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
