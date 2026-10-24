<?php

namespace App\Http\Resources\Report;

use App\Models\Comprobante;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Renglón del reporte de comprobantes: solo lo que el reporte muestra, sin
 * partidas ni cotización de origen (eso lo trae ComprobanteResource).
 *
 * @mixin Comprobante
 */
class ComprobanteReportItemResource extends JsonResource
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
            'date' => $this->date->toDateString(),
            'customer' => $this->customer?->name,
            'subtotal' => $this->subtotal,
            'total' => $this->total,
        ];
    }
}
