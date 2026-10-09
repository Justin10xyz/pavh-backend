<?php

namespace App\Http\Resources\Sale;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleResource extends JsonResource
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
            'quote' => $this->quote ? [
                'id' => $this->quote->id,
                'folio' => $this->quote->folio,
            ] : null,
            'customer_id' => $this->customer_id,
            'customer' => $this->customer ? [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
                'email' => $this->customer->email,
            ] : null,
            'subtotal' => $this->subtotal,
            'total' => $this->total,
            'created_by' => $this->created_by,
            'items' => SaleItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at,
        ];
    }
}
