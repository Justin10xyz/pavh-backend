<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\QuoteStatus;
use App\Models\SimpleProduct;
use App\Models\User;
use App\Services\QuotePdfGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\CreatesQuoteCustomers;
use Tests\TestCase;

/**
 * Abonos en el PDF del presupuesto: fila "Abonos" en la caja de totales
 * (TOTAL - Anticipo - Abonos = SALDO) y el detalle con fechas en el pie.
 * Mismo criterio que QuoteApiTest: se captura la estructura que arma el
 * controlador y se revisa el HTML de la plantilla.
 */
class QuotePdfInstallmentsTest extends TestCase
{
    use CreatesQuoteCustomers, RefreshDatabase;

    private SimpleProduct $simpleProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('sanctum.stateful')[0] ?? 'http://localhost:5173');

        $this->actingAs(User::factory()->create());

        foreach (['Borrador', 'Pagada', 'Cancelada'] as $name) {
            QuoteStatus::create(['name' => $name]);
        }

        $this->simpleProduct = SimpleProduct::create([
            'category_id' => Category::create(['name' => 'Materiales', 'code_prefix' => 'MAT', 'product_form_type' => 'simple'])->id,
            'name' => 'Pegazulejo gris 20kg',
            'price' => 100.00,
            'stock_quantity' => null,
        ]);
    }

    /**
     * Total $1,000.00 con anticipo de $200.00: saldo $800.00 antes de abonos.
     */
    private function createQuote(): int
    {
        return $this->postJson('/api/quotes', [
            'customer_id' => $this->quoteCustomerId(),
            'down_payment' => 200,
            'items' => [['simple_product_id' => $this->simpleProduct->id, 'quantity' => 10]],
        ])->assertCreated()->json('data.id');
    }

    private function addInstallment(int $quoteId, float $amount, Carbon $date): int
    {
        return $this->postJson("/api/quotes/{$quoteId}/installments", [
            'amount' => $amount,
            'payment_date' => $date->toDateString(),
        ])->assertCreated()->json('data.id');
    }

    /**
     * @return array<string, mixed> Lo que el controlador le pasa a QuotePdfGenerator.
     */
    private function capturePdfData(int $quoteId): array
    {
        $captured = null;
        $this->mock(QuotePdfGenerator::class, function ($mock) use (&$captured) {
            $mock->shouldReceive('generate')->once()->andReturnUsing(function (array $quote) use (&$captured) {
                $captured = $quote;

                return '%PDF-fake';
            });
        });

        $this->get("/api/quotes/{$quoteId}/pdf")->assertOk();

        return $captured;
    }

    /**
     * @param  array<string, mixed>  $quote
     */
    private function pdfText(array $quote): string
    {
        $html = view('pdf.presupuesto', ['quote' => $quote])->render();

        return preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html)));
    }

    public function test_it_shows_the_installments_row_and_each_installment_with_its_date(): void
    {
        $quoteId = $this->createQuote();
        $older = now()->subDays(10);
        $newer = now()->subDays(2);
        // Se registra primero el más reciente: el PDF los ordena por fecha.
        $this->addInstallment($quoteId, 250.50, $newer);
        $this->addInstallment($quoteId, 100, $older);

        $data = $this->capturePdfData($quoteId);

        $this->assertSame(350.5, $data['installments_total']);
        $this->assertSame([
            ['date' => $older->format('d/m/Y'), 'amount' => 100.0],
            ['date' => $newer->format('d/m/Y'), 'amount' => 250.5],
        ], $data['installments']);
        // TOTAL - Anticipo - Abonos = SALDO: 1000 - 200 - 350.50.
        $this->assertSame(449.5, $data['balance']);

        $text = $this->pdfText($data);

        $this->assertStringContainsString('Anticipo -$200.00 Abonos -$350.50 SALDO $449.50', $text);
        $this->assertStringContainsString("{$older->format('d/m/Y')} \$100.00", $text);
        $this->assertStringContainsString("{$newer->format('d/m/Y')} \$250.50", $text);
        $this->assertStringNotContainsString('anterior', $text);
    }

    public function test_a_voided_installment_does_not_appear(): void
    {
        $quoteId = $this->createQuote();
        $this->addInstallment($quoteId, 100, now()->subDays(3));
        $voided = $this->addInstallment($quoteId, 77.77, now()->subDay());
        $this->deleteJson("/api/quotes/{$quoteId}/installments/{$voided}")->assertOk();

        $data = $this->capturePdfData($quoteId);

        $this->assertSame(100.0, $data['installments_total']);
        $this->assertCount(1, $data['installments']);

        $text = $this->pdfText($data);

        $this->assertStringContainsString('Abonos -$100.00 SALDO $700.00', $text);
        $this->assertStringNotContainsString('77.77', $text);
    }

    public function test_without_installments_the_pdf_stays_as_before(): void
    {
        $data = $this->capturePdfData($this->createQuote());

        $this->assertSame(0.0, $data['installments_total']);
        $this->assertSame([], $data['installments']);

        $html = view('pdf.presupuesto', ['quote' => $data])->render();

        $this->assertStringNotContainsString('Abonos', $html);
        $this->assertStringNotContainsString('class="installments"', $html);
        $this->assertStringContainsString('SALDO', $html);

        // Igual que con la estructura de antes de los abonos (sin esas llaves).
        $before = $data;
        unset($before['installments_total'], $before['installments']);

        $this->assertSame(view('pdf.presupuesto', ['quote' => $before])->render(), $html);
    }

    public function test_the_footer_shows_only_the_five_most_recent_installments(): void
    {
        $quoteId = $this->createQuote();
        // 7 abonos de $10 a $70, del más antiguo al más reciente.
        foreach (range(1, 7) as $i) {
            $this->addInstallment($quoteId, 10 * $i, now()->subDays(20 - $i));
        }

        $data = $this->capturePdfData($quoteId);
        $this->assertCount(7, $data['installments']);

        $text = $this->pdfText($data);

        // La fila de la caja suma los 7: 10 + 20 + … + 70.
        $this->assertStringContainsString('Abonos -$280.00', $text);
        foreach ([30, 40, 50, 60, 70] as $amount) {
            $this->assertStringContainsString(number_format($amount, 2).' ', $text);
        }
        $this->assertStringNotContainsString(now()->subDays(19)->format('d/m/Y'), $text);
        $this->assertStringNotContainsString(now()->subDays(18)->format('d/m/Y'), $text);
        $this->assertStringContainsString('y 2 abonos anteriores', $text);
    }

    public function test_one_hidden_installment_uses_the_singular(): void
    {
        $quoteId = $this->createQuote();
        foreach (range(1, 6) as $i) {
            $this->addInstallment($quoteId, 10, now()->subDays(10 - $i));
        }

        $text = $this->pdfText($this->capturePdfData($quoteId));

        $this->assertStringContainsString('y 1 abono anterior', $text);
        $this->assertStringNotContainsString('abonos anteriores', $text);
    }

    public function test_a_full_totals_box_with_installments_leaves_one_line_less_on_the_last_sheet(): void
    {
        $quote = $this->capturePdfData($this->createQuote());

        // 41 líneas: con la caja de 7 renglones caben en 2 hojas (20 + 21);
        // con descuento, envío, anticipo y abonos (8) la última lleva 20 y
        // sobra una para una tercera hoja.
        $quote['items'] = array_fill(0, 41, $quote['items'][0]);
        $full = [...$quote, 'discount' => 50.0, 'shipping_cost' => 50.0];

        $this->assertStringContainsString('Hoja 2 de 2', $this->pdfText($full));
        $this->assertStringNotContainsString('Hoja 3', $this->pdfText($full));

        $withInstallments = [
            ...$full,
            'installments_total' => 100.0,
            'installments' => [['date' => '01/11/2026', 'amount' => 100.0]],
        ];

        $this->assertStringContainsString('Hoja 3 de 3', $this->pdfText($withInstallments));
    }
}
