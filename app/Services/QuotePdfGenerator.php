<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdfDocument;

/**
 * Genera el PDF del Presupuesto (cotización). Mismo tamaño de hoja y papel, logo,
 * bloque de negocio, cliente y tabla de líneas que el Comprobante Fiscal
 * Simplificado, pero con banda de color y caja de totales con saldo para que
 * no se confundan; sin datos fiscales (RFC/idCIF, régimen, lugar de
 * expedición, total con letra). Mismo criterio que ComprobantePdfGenerator:
 * recibe una estructura neutral armada por el controlador, no modelos
 * Eloquent, y los campos null se dejan en blanco.
 *
 * [
 *   'folio' => string,
 *   'date' => string, // ya formateada para mostrarse
 *   'business' => [   // business_settings
 *     'address' => ?string, 'neighborhood' => ?string, 'city' => ?string,
 *     'postal_code' => ?string, 'phone_primary' => ?string,
 *     'phone_secondary' => ?string, 'email' => ?string,
 *   ],
 *   'customer' => [
 *     'name' => string, 'address' => ?string, 'neighborhood' => ?string,
 *     'city' => ?string, 'postal_code' => ?string, 'phone' => ?string,
 *   ] | null,
 *   'items' => [...], // misma forma que en ComprobantePdfGenerator
 *   'subtotal' => float,
 *   'discount' => float,      // 0 si no aplica; solo se muestra si > 0
 *   'shipping_cost' => float, // 0 si no aplica; solo se muestra si > 0
 *   'total' => float,         // quotes.total tal cual: subtotal - descuento + envío
 *   'down_payment' => float,  // 0 si no aplica; solo se muestra si > 0
 *   'installments_total' => float, // abonos vigentes; 0 si no hay; fila solo si > 0
 *   'installments' => [         // abonos vigentes, del más antiguo al más reciente;
 *     ['date' => string, 'amount' => float], ...  // date ya formateada (d/m/Y)
 *   ],                          // el pie muestra a lo más los 5 más recientes
 *   'balance' => float,       // total - anticipo - abonos; puede ser negativo
 *   'payment_terms' => 'cash' | 'credit',
 *   'credit_due_date' => ?string, // ya formateada; solo con crédito
 * ]
 */
class QuotePdfGenerator
{
    // Hoja y papel: ver ComprobantePdfGenerator.
    public const SHEET_WIDTH = ComprobantePdfGenerator::SHEET_WIDTH;

    public const SHEET_HEIGHT = ComprobantePdfGenerator::SHEET_HEIGHT;

    public const PAPER_WIDTH = ComprobantePdfGenerator::PAPER_WIDTH;

    public const PAPER_HEIGHT = ComprobantePdfGenerator::PAPER_HEIGHT;

    public const SHEETS_PER_PAPER = 2;

    /**
     * @param  array<string, mixed>  $quote  Estructura descrita en el docblock de la clase.
     * @return string Contenido binario del PDF.
     */
    public function generate(array $quote): string
    {
        return $this->document($quote)->output();
    }

    /**
     * El documento armado, sin renderizar: el que generate() convierte en PDF.
     * Expuesto para que los tests midan el layout de dompdf de lo mismo que se
     * entrega (ver HalfSheetPdfPaperTest).
     *
     * @param  array<string, mixed>  $quote  Estructura descrita en el docblock de la clase.
     */
    public function document(array $quote): DomPdfDocument
    {
        return Pdf::loadView('pdf.presupuesto', [
            'quote' => $quote,
            ...ComprobantePdfGenerator::sheetViewData(self::SHEETS_PER_PAPER),
        ])
            ->setPaper([0, 0, self::PAPER_WIDTH, self::PAPER_HEIGHT]);
    }
}
