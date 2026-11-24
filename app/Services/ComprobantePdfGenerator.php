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
    // Hoja: media carta horizontal (8.5" x 5.5"), el tamaño del talonario real.
    // Es la unidad de diseño: PdfSheetLayout reparte las líneas en hojas y la
    // plantilla dimensiona logo y cédula como fracción de su alto.
    public const SHEET_WIDTH = 612;

    public const SHEET_HEIGHT = 396;

    // Papel: carta vertical (8.5" x 11"). El cliente imprime en hoja carta
    // completa; los visores centran una página más chica que el papel, así
    // que el PDF ya mide carta y cada hoja va en su mitad.
    public const PAPER_WIDTH = 612;

    public const PAPER_HEIGHT = 792;

    // Hojas por carta: 2 = hoja 1 arriba y hoja 2 abajo, hoja 3 arriba de la
    // siguiente carta...; 1 = cada hoja arriba de su propia carta.
    public const SHEETS_PER_PAPER = 2;

    /**
     * @param  array<string, mixed>  $comprobante  Estructura descrita en el docblock de la clase.
     * @return string Contenido binario del PDF.
     */
    public function generate(array $comprobante): string
    {
        return Pdf::loadView('pdf.comprobante', [
            'comprobante' => $comprobante,
            ...self::sheetViewData(self::SHEETS_PER_PAPER),
        ])
            ->setPaper([0, 0, self::PAPER_WIDTH, self::PAPER_HEIGHT])
            ->output();
    }

    /**
     * Medidas de hoja y papel que esperan las plantillas de media carta
     * (Comprobante y Presupuesto) y sheet-styles.
     *
     * @return array{sheet: array{width: int, height: int}, paper: array{width: int, height: int, sheets: int}}
     */
    public static function sheetViewData(int $sheetsPerPaper): array
    {
        return [
            'sheet' => ['width' => self::SHEET_WIDTH, 'height' => self::SHEET_HEIGHT],
            'paper' => ['width' => self::PAPER_WIDTH, 'height' => self::PAPER_HEIGHT, 'sheets' => $sheetsPerPaper],
        ];
    }
}
