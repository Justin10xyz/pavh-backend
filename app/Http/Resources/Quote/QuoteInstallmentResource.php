<?php

namespace App\Http\Resources\Quote;

use App\Models\QuoteInstallment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin QuoteInstallment
 */
class QuoteInstallmentResource extends JsonResource
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
            'amount' => $this->amount,
            // Solo día: sin hora ni zona, para que no se recorra al serializar.
            'payment_date' => $this->payment_date->toDateString(),
            'notes' => $this->notes,
            'created_by' => $this->created_by,
            // Un Resource no pasa por serializeDate(); mismo formato que SerializesDatesInAppTimezone.
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
