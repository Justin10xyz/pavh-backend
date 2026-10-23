<?php

namespace App\Http\Controllers\Report;

use App\Http\Controllers\Controller;
use App\Http\Requests\Report\ComprobanteReportRequest;
use App\Http\Resources\Report\ComprobanteReportItemResource;
use App\Models\Comprobante;

class ComprobanteReportController extends Controller
{
    /**
     * @return array<string, mixed>
     */
    public function comprobantes(ComprobanteReportRequest $request): array
    {
        $data = $request->validated();

        // La relación customer ya incluye clientes dados de baja (withTrashed
        // en el modelo).
        $comprobantes = Comprobante::query()
            ->with('customer')
            ->dateRange($data['from'], $data['to'])
            ->orderBy('date')
            ->get();

        // sum() no respeta el cast decimal:2 y devuelve float nativo:
        // number_format() lo deja en 2 decimales exactos, sin residuos de
        // punto flotante.
        return [
            'items' => ComprobanteReportItemResource::collection($comprobantes),
            'summary' => [
                'count' => $comprobantes->count(),
                'subtotal' => number_format($comprobantes->sum('subtotal'), 2, '.', ''),
                'total' => number_format($comprobantes->sum('total'), 2, '.', ''),
            ],
        ];
    }
}
