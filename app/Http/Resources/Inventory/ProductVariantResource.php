<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductVariantResource extends JsonResource
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
            'code' => $this->code,
            'supplier_code' => $this->supplier_code,
            'color' => $this->color,
            'size' => $this->size,
            'price_per_m2' => $this->price_per_m2,
            'price_per_box' => $this->price_per_box,
            'pieces_per_box' => $this->pieces_per_box,
            'm2_per_box' => $this->m2_per_box,
            'kilos_per_box' => $this->kilos_per_box,
            'boxes_per_pallet' => $this->boxes_per_pallet,
            'stock_boxes' => $this->stock_boxes,
            'minimum_stock' => $this->minimum_stock,
            'low_stock' => $this->minimum_stock !== null && $this->stock_boxes <= $this->minimum_stock,
            'pei' => $this->pei,
            'ett' => $this->ett,
            'commission_category' => $this->whenLoaded('commissionCategory', fn () => $this->commissionCategory ? [
                'id' => $this->commissionCategory->id,
                'code' => $this->commissionCategory->code,
            ] : null),
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'name' => $this->product->name,
            ]),
        ];
    }
}
