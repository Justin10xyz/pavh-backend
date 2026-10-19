<?php

namespace App\Http\Resources\Settings;

use App\Models\BusinessSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BusinessSetting
 */
class BusinessSettingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'place_of_issue' => $this->place_of_issue,
            'business_regime' => $this->business_regime,
            'tax_id' => $this->tax_id,
            'tax_id_number' => $this->tax_id_number,
            'address' => $this->address,
            'neighborhood' => $this->neighborhood,
            'city' => $this->city,
            'postal_code' => $this->postal_code,
            'phone_primary' => $this->phone_primary,
            'phone_secondary' => $this->phone_secondary,
            'email' => $this->email,
            // Un Resource no pasa por serializeDate(); mismo formato que SerializesDatesInAppTimezone.
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
