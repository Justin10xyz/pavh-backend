<?php

namespace App\Http\Resources\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            'created_at' => $this->created_at,
        ];
    }
}
