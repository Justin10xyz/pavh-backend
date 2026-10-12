<?php

namespace App\Http\Resources\Quote;

use App\Http\Resources\Inventory\ProductVariantResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuoteItemResource extends JsonResource
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
            'product_variant_id' => $this->product_variant_id,
            'product_variant' => $this->productVariant ? new ProductVariantResource($this->productVariant) : null,
            'simple_product_id' => $this->simple_product_id,
            'simple_product' => $this->whenLoaded('simpleProduct', fn () => $this->simpleProduct ? [
                'id' => $this->simpleProduct->id,
                'name' => $this->simpleProduct->name,
                'price' => $this->simpleProduct->price,
            ] : null),
            // Líneas de variante: cajas (cantidad real) y los m² que cubren.
            // Líneas simples: boxes null, quantity en unidades.
            'boxes' => $this->boxes,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'line_total' => $this->line_total,
        ];
    }
}
