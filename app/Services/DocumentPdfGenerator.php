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
 *   'customer' => [
 *     'name' => string, 'phone' => ?string, 'email' => ?string,
 *     'address' => ?string, 'neighborhood' => ?string, 'city' => ?string,
 *   ] | null,
 *   'items' => [[
 *     'variant_label' => string, // variante: "línea color medida"; producto simple: su nombre
 *     'unit' => 'm2' | 'uds',    // variante: cajas + m²; producto simple o variante por pieza: unidades
 *     'boxes' => ?int,           // variante: cajas completas (o piezas) vendidas/cotizadas; simple: null
 *     'quantity' => float,       // variante: m² que cubren esas cajas (por pieza: las piezas); simple: unidades
 *     'unit_price' => float,
 *     'price_type' => 'retail' | 'wholesale', // wholesale solo en productos simples a mayoreo
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
