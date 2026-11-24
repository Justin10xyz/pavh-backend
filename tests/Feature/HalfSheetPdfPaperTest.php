<?php

namespace Tests\Feature;

use App\Services\ComprobantePdfGenerator;
use App\Services\QuotePdfGenerator;
use Barryvdh\DomPDF\PDF as DomPdfDocument;
use DOMElement;
use Dompdf\Canvas;
use Dompdf\Frame;
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

    /**
     * Peor caso medido de PdfSheetLayout: 40 líneas con la caja de totales
     * completa (8 renglones) y abonos son 2 hojas (20 + 20), y la última, con
     * todo el pie, es la de abajo de la carta. Se mide con el layout de dompdf
     * del mismo documento que entrega generate(): cada hoja, par o impar,
     * debe tener su padding por posición y su contenido caber en los 364pt
     * interiores; el pie debe quedar dentro de la mitad inferior.
     */
    public function test_the_last_sheet_with_full_totals_and_installments_fits_in_the_bottom_half(): void
    {
        $installments = array_map(fn (int $day) => ['date' => sprintf('%02d/11/2026', $day), 'amount' => 1500.0], range(1, 7));
        $document = app(QuotePdfGenerator::class)->document([
            'folio' => 'P-0042',
            'date' => '23/11/2026',
            'business' => self::BUSINESS,
            'customer' => [
                'name' => 'Juan Pérez Hernández Construcciones', 'address' => 'Calle Morelos 45', 'neighborhood' => 'San José',
                'city' => 'Teocaltiche', 'postal_code' => '47200', 'phone' => '346 111 2222',
            ],
            'items' => array_fill(0, 40, self::ITEM),
            'subtotal' => 269222.4,
            'discount' => 500.0,
            'shipping_cost' => 350.0,
            'total' => 269072.4,
            'down_payment' => 10000.0,
            'installments_total' => 10500.0,
            'installments' => $installments,
            'balance' => 248572.4,
            'payment_terms' => 'credit',
            'credit_due_date' => '31/12/2026',
        ]);

        [$sheets, $footers] = $this->layout($document);

        $this->assertCount(2, $sheets);

        // Primero la holgura de cada hoja (impar y par): si la de abajo desborda,
        // dompdf la pasa a otra carta y el resto de las aserciones fallaría
        // sin decir por qué.
        foreach ($sheets as $index => $measured) {
            $this->assertGreaterThanOrEqual(0.0, $measured['slack'], sprintf('Hoja %d: el contenido (%.3fpt) rebasa los 364pt interiores', $index + 1, $measured['content']));
        }
        $this->assertSame(1, $document->getDomPDF()->getCanvas()->get_page_count());

        // Hoja 1 (impar): arriba, 22 contra el borde superior de la carta y 10 hacia la mitad.
        // Hoja 2 (par): abajo, 10 hacia la mitad y 22 contra el borde inferior.
        $expected = [
            ['position' => 'top', 'y' => 0.0, 'padding' => [22.0, 10.0]],
            ['position' => 'bottom', 'y' => 396.0, 'padding' => [10.0, 22.0]],
        ];
        foreach ($expected as $index => $sheet) {
            $measured = $sheets[$index];
            $label = 'Hoja '.($index + 1);
            $this->assertSame(1, $measured['page'], $label);
            $this->assertSame($sheet['position'], $measured['position'], $label);
            $this->assertEqualsWithDelta($sheet['y'], $measured['y'], 0.01, $label);
            $this->assertEqualsWithDelta(396.0, $measured['height'], 0.01, $label);
            $this->assertSame($sheet['padding'], $measured['padding'], $label);
        }

        // El pie (solo en la última hoja) queda dentro del área interior de la de abajo.
        $this->assertCount(1, $footers);
        $this->assertGreaterThanOrEqual(396.0 + 10.0, $footers[0]['top']);
        $this->assertLessThanOrEqual(792.0 - 22.0, $footers[0]['bottom']);
    }

    /**
     * Renderiza el documento y devuelve, en orden, cada .sheet (página,
     * posición, y, alto, padding arriba/abajo, alto del contenido y holgura
     * sobre su alto interior) y la caja de cada .footer, en pt desde el borde
     * superior de la carta.
     *
     * @return array{0: list<array{page: int, position: string, y: float, height: float, padding: array{float, float}, content: float, slack: float}>, 1: list<array{top: float, bottom: float}>}
     */
    private function layout(DomPdfDocument $document): array
    {
        $sheets = [];
        $footers = [];
        $dompdf = $document->getDomPDF();
        $dompdf->setCallbacks([['event' => 'end_frame', 'f' => function (Frame $frame, Canvas $canvas) use (&$sheets, &$footers) {
            $node = $frame->get_node();
            $classes = $node instanceof DOMElement ? explode(' ', $node->getAttribute('class')) : [];
            $position = $frame->get_position();

            if (in_array('footer', $classes, true)) {
                $footers[] = ['top' => $position['y'], 'bottom' => $position['y'] + $frame->get_margin_height()];
            }

            if (! in_array('sheet', $classes, true)) {
                return;
            }

            $style = $frame->get_style();
            $paddingTop = (float) $style->padding_top;
            $paddingBottom = (float) $style->padding_bottom;
            $height = $frame->get_margin_height();
            $contentTop = $position['y'] + $paddingTop;
            $contentBottom = $contentTop;
            foreach ($frame->get_children() as $child) {
                if ($child->get_node()->nodeName !== '#text') {
                    $contentBottom = max($contentBottom, $child->get_position()['y'] + $child->get_margin_height());
                }
            }
            $content = $contentBottom - $contentTop;

            $sheets[] = [
                'page' => $canvas->get_page_number(),
                'position' => in_array('sheet-bottom', $classes, true) ? 'bottom' : 'top',
                'y' => $position['y'],
                'height' => $height,
                'padding' => [$paddingTop, $paddingBottom],
                'content' => $content,
                'slack' => $height - $paddingTop - $paddingBottom - $content,
            ];
        }]]);
        $dompdf->render();

        return [$sheets, $footers];
    }

    private function assertLetterPages(string $pdf, int $pages): void
    {
        preg_match_all('/\/MediaBox \[([^\]]+)\]/', $pdf, $boxes);
        $this->assertSame(['0.000 0.000 612.000 792.000'], array_values(array_unique($boxes[1])));

        preg_match_all('/\/Count (\d+)/', $pdf, $counts);
        $this->assertSame($pages, max(array_map('intval', $counts[1])));
    }
}
