<?php

namespace Tests\Feature;

use App\Services\ComprobantePdfGenerator;
use App\Services\QuotePdfGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Presupuesto y Comprobante se generan en carta vertical (612x792) con dos
 * hojas de media carta por carta (ver ComprobantePdfGenerator): el número de
 * hojas no cambia, solo cuántas cartas ocupan. Se revisa el PDF real que
 * produce dompdf: tamaño de cada página y número de páginas.
 */
class HalfSheetPdfPaperTest extends TestCase
{
    private const ITEM = [
        'description' => 'Porcelanato Marmolizado Blanco 60x60',
        'unit' => 'm2',
        'boxes' => 12,
        'quantity' => 17.28,
        'unit_price' => 389.5,
        'line_total' => 6730.56,
    ];

    private const BUSINESS = [
        'address' => 'Av. Hidalgo 1234', 'neighborhood' => 'Centro', 'city' => 'Villa Hidalgo, Jalisco',
        'postal_code' => '47250', 'phone_primary' => '495 123 4567', 'phone_secondary' => null, 'email' => null,
        'place_of_issue' => null, 'business_regime' => null, 'tax_id' => null, 'tax_id_number' => null,
    ];

    /**
     * Líneas => cartas: 10 (1 hoja), 41 (2 hojas: 20 + 21) y, con la caja de
     * totales de 8 renglones, 41 (3 hojas: 20 + 20 + 1).
     *
     * @return array<string, array{int, bool, int}>
     */
    public static function quoteSheets(): array
    {
        return [
            'una hoja' => [10, false, 1],
            'dos hojas en una carta' => [41, false, 1],
            'tres hojas, abonos en la última' => [41, true, 2],
        ];
    }

    #[DataProvider('quoteSheets')]
    public function test_the_quote_pdf_is_letter_with_two_sheets_per_page(int $lines, bool $fullTotals, int $pages): void
    {
        $pdf = app(QuotePdfGenerator::class)->generate([
            'folio' => 'P-0001',
            'date' => '23/11/2026',
            'business' => self::BUSINESS,
            'customer' => null,
            'items' => array_fill(0, $lines, self::ITEM),
            'subtotal' => 1000.0,
            'discount' => $fullTotals ? 50.0 : 0,
            'shipping_cost' => $fullTotals ? 50.0 : 0,
            'total' => 1000.0,
            'down_payment' => $fullTotals ? 100.0 : 0,
            'installments_total' => $fullTotals ? 100.0 : 0,
            'installments' => $fullTotals ? [['date' => '01/11/2026', 'amount' => 100.0]] : [],
            'balance' => 800.0,
            'payment_terms' => 'cash',
            'credit_due_date' => null,
        ]);

        $this->assertLetterPages($pdf, $pages);
    }

    public function test_the_comprobante_pdf_is_letter_with_two_sheets_per_page(): void
    {
        $comprobante = fn (int $lines) => app(ComprobantePdfGenerator::class)->generate([
            'folio' => 'CF-0001',
            'date' => '23/11/2026',
            'business' => self::BUSINESS,
            'customer' => null,
            'items' => array_fill(0, $lines, self::ITEM),
            'total' => 1000.0,
            'total_in_words' => 'MIL PESOS 00/100 M.N.',
        ]);

        $this->assertLetterPages($comprobante(2), 1);
        // 73 líneas: 20 + 32 + 21, tres hojas en dos cartas.
        $this->assertLetterPages($comprobante(73), 2);
    }

    private function assertLetterPages(string $pdf, int $pages): void
    {
        preg_match_all('/\/MediaBox \[([^\]]+)\]/', $pdf, $boxes);
        $this->assertSame(['0.000 0.000 612.000 792.000'], array_values(array_unique($boxes[1])));

        preg_match_all('/\/Count (\d+)/', $pdf, $counts);
        $this->assertSame($pages, max(array_map('intval', $counts[1])));
    }
}
