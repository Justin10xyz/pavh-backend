<?php

namespace App\Http\Resources\Comprobante;

use App\Http\Resources\Inventory\ProductVariantResource;
use App\Models\ComprobanteItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ComprobanteItem
 */
class ComprobanteItemResource extends JsonResource
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
            'product_variant' => $this->whenLoaded('productVariant', fn () => $this->productVariant ? new ProductVariantResource($this->productVariant) : null),
            'simple_product_id' => $this->simple_product_id,
            'simple_product' => $this->whenLoaded('simpleProduct', fn () => $this->simpleProduct ? [
                'id' => $this->simpleProduct->id,
                'name' => $this->simpleProduct->name,
            ] : null),
            // Misma forma que la línea de cotización de la que se copió.
            'boxes' => $this->boxes,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'price_type' => $this->price_type,
            'line_total' => $this->line_total,
        ];
    }
}
