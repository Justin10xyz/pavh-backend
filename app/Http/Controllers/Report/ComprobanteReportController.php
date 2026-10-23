<?php

namespace App\Http\Controllers\Report;

use App\Http\Controllers\Controller;
use App\Http\Requests\Report\ComprobanteReportRequest;
use App\Http\Resources\Report\ComprobanteReportItemResource;
use App\Models\Comprobante;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ComprobanteReportController extends Controller
{
    /**
     * @return array<string, mixed>
     */
    public function comprobantes(ComprobanteReportRequest $request): array
    {
        $comprobantes = $this->comprobantesFor($request->validated());

        return [
            'items' => ComprobanteReportItemResource::collection($comprobantes),
            'summary' => $this->summaryFor($comprobantes),
        ];
    }

    /**
     * Mismas filas y totales que comprobantes(). 'Sin cliente' se resuelve aquí
     * (el JSON da null) porque el CSV no pasa por el frontend: va directo al
     * archivo que se abre en Excel. El BOM hace que Excel lo lea como UTF-8.
     */
    public function csv(ComprobanteReportRequest $request): StreamedResponse
    {
        $data = $request->validated();
        $comprobantes = $this->comprobantesFor($data);
        $summary = $this->summaryFor($comprobantes);

        $filename = "Reporte-Comprobantes-{$data['from']}-a-{$data['to']}.csv";

        return response()->streamDownload(function () use ($comprobantes, $summary) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");

            // escape '': comillas RFC 4180 (las de Excel); con el "\\" por
            // defecto un nombre que termine en diagonal invertida rompe la fila.
            fputcsv($handle, ['Folio', 'Fecha', 'Cliente', 'Subtotal', 'Total'], escape: '');

            foreach ($comprobantes as $comprobante) {
                fputcsv($handle, [
                    $comprobante->folio,
                    $comprobante->date->toDateString(),
                    $comprobante->customer?->name ?? 'Sin cliente',
                    $comprobante->subtotal,
                    $comprobante->total,
                ], escape: '');
            }

            fputcsv($handle, [
                'TOTALES',
                '',
                $summary['count'].' comprobantes',
                $summary['subtotal'],
                $summary['total'],
            ], escape: '');

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * La relación customer ya incluye clientes dados de baja (withTrashed en
     * el modelo).
     *
     * @param  array<string, mixed>  $data
     * @return Collection<int, Comprobante>
     */
    private function comprobantesFor(array $data): Collection
    {
        return Comprobante::query()
            ->with('customer')
            ->dateRange($data['from'], $data['to'])
            ->orderBy('date')
            ->get();
    }

    /**
     * sum() no respeta el cast decimal:2 y devuelve float nativo:
     * number_format() lo deja en 2 decimales exactos, sin residuos de punto
     * flotante.
     *
     * @param  Collection<int, Comprobante>  $comprobantes
     * @return array{count: int, subtotal: string, total: string}
     */
    private function summaryFor(Collection $comprobantes): array
    {
        return [
            'count' => $comprobantes->count(),
            'subtotal' => number_format($comprobantes->sum('subtotal'), 2, '.', ''),
            'total' => number_format($comprobantes->sum('total'), 2, '.', ''),
        ];
    }
}
