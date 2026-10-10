<?php

namespace App\Http\Resources\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
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
            'name' => $this->name,
            'supplier_id' => $this->supplier_id,
            'supplier' => $this->supplier->name,
            'category_id' => $this->category_id,
            'category' => $this->category->name,
            'unit_type_id' => $this->unit_type_id,
            'unit_type' => $this->unitType->name,
            'purchase_unit' => $this->purchase_unit,
            'variants' => ProductVariantResource::collection($this->whenLoaded('variants')),
        ];
    }
}
