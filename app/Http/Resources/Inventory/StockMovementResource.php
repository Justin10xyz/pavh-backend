<?php

namespace App\Http\Resources\Inventory;

use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockMovement
 */
class StockMovementResource extends JsonResource
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
            'quantity' => $this->quantity,
            'type' => $this->type,
            'source' => $this->source,
            'resulting_stock' => $this->resulting_stock,
            'sale_folio' => $this->whenLoaded('sale', fn () => $this->sale?->folio),
            // Para enlazar a la venta desde el historial. Una venta eliminada
            // (soft delete) conserva su folio arriba, pero su detalle ya no
            // existe (GET /sales/{id} da 404), así que no se enlaza.
            'sale_id' => $this->whenLoaded('sale', fn () => $this->sale && ! $this->sale->trashed() ? $this->sale->id : null),
            // Un Resource no pasa por serializeDate(); mismo formato que SerializesDatesInAppTimezone.
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
