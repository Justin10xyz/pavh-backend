<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Genera el PDF del reporte de comprobantes por periodo. Mismo criterio que
 * ComprobantePdfGenerator: recibe una estructura neutral armada por el
 * controlador, no modelos Eloquent. Un periodo sin comprobantes genera el PDF
 * igual (tabla vacía y totales en cero).
 *
 * [
 *   'period_label' => string, // ver SpanishMonthName::periodLabel()
 *   'generated_at' => string, // ya formateada para mostrarse
 *   'items' => [[
 *     'folio' => string,
 *     'date' => string,       // ya formateada para mostrarse
 *     'customer' => string,   // 'Sin cliente' si no tiene
 *     'subtotal' => string,
 *     'total' => string,
 *   ], ...],
 *   'summary' => ['count' => int, 'subtotal' => string, 'total' => string],
 * ]
 */
class ComprobanteReportPdfGenerator
{
    // Carta vertical (8.5" x 11") — reporte interno, no el talonario físico.
    public const PAGE_WIDTH = 612;

    public const PAGE_HEIGHT = 792;

    /**
     * @param  array<string, mixed>  $report  Estructura descrita en el docblock de la clase.
     * @return string Contenido binario del PDF.
     */
    public function generate(array $report): string
    {
        return Pdf::loadView('pdf.comprobante-report', [
            'report' => $report,
            'page' => ['width' => self::PAGE_WIDTH, 'height' => self::PAGE_HEIGHT],
        ])
            ->setPaper([0, 0, self::PAGE_WIDTH, self::PAGE_HEIGHT])
            ->output();
    }
}
