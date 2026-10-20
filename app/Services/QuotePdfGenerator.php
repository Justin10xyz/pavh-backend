<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Genera el PDF del Presupuesto (cotización). Mismo tamaño de página, logo,
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
 *   'total' => float,         // subtotal - descuento + envío
 *   'down_payment' => float,  // 0 si no aplica; solo se muestra si > 0
 *   'balance' => float,       // total - anticipo; puede ser negativo
 *   'payment_terms' => 'cash' | 'credit',
 *   'credit_due_date' => ?string, // ya formateada; solo con crédito
 * ]
 */
class QuotePdfGenerator
{
    public const PAGE_WIDTH = ComprobantePdfGenerator::PAGE_WIDTH;

    public const PAGE_HEIGHT = ComprobantePdfGenerator::PAGE_HEIGHT;

    /**
     * @param  array<string, mixed>  $quote  Estructura descrita en el docblock de la clase.
     * @return string Contenido binario del PDF.
     */
    public function generate(array $quote): string
    {
        return Pdf::loadView('pdf.presupuesto', [
            'quote' => $quote,
            'page' => ['width' => self::PAGE_WIDTH, 'height' => self::PAGE_HEIGHT],
        ])
            ->setPaper([0, 0, self::PAGE_WIDTH, self::PAGE_HEIGHT])
            ->output();
    }
}
