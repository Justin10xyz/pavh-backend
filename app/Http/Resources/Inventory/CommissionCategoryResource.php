<?php

namespace App\Http\Resources\Inventory;

use App\Models\CommissionCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CommissionCategory
 */
class CommissionCategoryResource extends JsonResource
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
            'name' => $this->name,
            'tier' => $this->tier,
            'percentage' => $this->percentage,
            'notes' => $this->notes,
        ];
    }
}
