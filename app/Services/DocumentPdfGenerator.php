<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Genera el PDF de una "nota" (venta o cotización) a partir de una estructura
 * neutral — no recibe modelos Eloquent, cada controlador arma los datos desde
 * su propio modelo:
 *
 * [
 *   'document_type' => 'Venta' | 'Cotización',
 *   'folio' => string,
 *   'date' => string, // ya formateada para mostrarse
 *   'customer' => ['name' => string, 'phone' => ?string, 'email' => ?string] | null,
 *   'items' => [[
 *     'variant_label' => string, // variante: "línea color medida"; producto simple: su nombre
 *     'unit' => 'm2' | 'uds',    // variante en m², producto simple en unidades
 *     'quantity' => float,
 *     'unit_price' => float,
 *     'line_total' => float,
 *   ], ...],
 *   'subtotal' => float,
 *   'total' => float,
 * ]
 */
class DocumentPdfGenerator
{
    // Media carta (5.5" x 8.5"), el tamaño de las notas a mano que usa hoy el cliente.
    public const DEFAULT_PAPER = 'half-letter';

    /**
     * @param  string|array<int, float>  $paper  Nombre de tamaño de dompdf (ej. 'letter') o [x0, y0, x1, y1] en puntos.
     * @return string Contenido binario del PDF.
     */
    public function generate(array $document, string|array $paper = self::DEFAULT_PAPER, string $orientation = 'portrait'): string
    {
        return Pdf::loadView('pdf.document', ['document' => $document])
            ->setPaper($paper, $orientation)
            ->output();
    }
}
