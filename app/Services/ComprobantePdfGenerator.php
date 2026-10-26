<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Genera el PDF del Comprobante Fiscal Simplificado, con el formato del papel
 * membretado. Recibe una estructura neutral armada por el controlador, no
 * modelos Eloquent. Ningún dato faltante
 * bloquea la generación: los campos null se dejan en blanco.
 *
 * [
 *   'folio' => string,
 *   'date' => string, // ya formateada para mostrarse
 *   'business' => [   // business_settings
 *     'place_of_issue' => ?string, 'business_regime' => ?string,
 *     'tax_id' => ?string, 'tax_id_number' => ?string,
 *     'address' => ?string, 'neighborhood' => ?string, 'city' => ?string,
 *     'postal_code' => ?string, 'phone_primary' => ?string,
 *     'phone_secondary' => ?string, 'email' => ?string,
 *   ],
 *   'customer' => [
 *     'name' => string, 'address' => ?string, 'neighborhood' => ?string,
 *     'city' => ?string, 'postal_code' => ?string, 'phone' => ?string,
 *   ] | null,
 *   'items' => [[
 *     'description' => string, // variante: "línea color medida"; producto simple: su nombre
 *     'unit' => 'm2' | 'uds',  // variante: cajas + m²; producto simple o variante por pieza: unidades
 *     'boxes' => ?int,
 *     'quantity' => float,
 *     'unit_price' => float,
 *     'line_total' => float,
 *   ], ...],
 *   'total' => float,
 *   'total_in_words' => ?string,
 * ]
 */
class ComprobantePdfGenerator
{
    // Media carta horizontal (8.5" x 5.5"), el tamaño del talonario real.
    public const PAGE_WIDTH = 612;

    public const PAGE_HEIGHT = 396;

    /**
     * @param  array<string, mixed>  $comprobante  Estructura descrita en el docblock de la clase.
     * @return string Contenido binario del PDF.
     */
    public function generate(array $comprobante): string
    {
        // La plantilla dimensiona algunas cajas como fracción de la página.
        return Pdf::loadView('pdf.comprobante', [
            'comprobante' => $comprobante,
            'page' => ['width' => self::PAGE_WIDTH, 'height' => self::PAGE_HEIGHT],
        ])
            ->setPaper([0, 0, self::PAGE_WIDTH, self::PAGE_HEIGHT])
            ->output();
    }
}
