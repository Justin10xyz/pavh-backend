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
            // null-safe por si la relación se carga sin withTrashed() y la
            // cotización se dio de baja.
            'quote' => $this->whenLoaded('quote', fn () => $this->quote ? [
                'id' => $this->quote->id,
                'folio' => $this->quote->folio,
            ] : null),
            'customer_id' => $this->customer_id,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
                'email' => $this->customer->email,
                // Domicilio: lo mismo que imprime el PDF.
                'address' => $this->customer->address,
                'neighborhood' => $this->customer->neighborhood,
                'city' => $this->customer->city,
                'postal_code' => $this->customer->postal_code,
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
