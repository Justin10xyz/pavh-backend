<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Quote;
use App\Models\QuoteInstallment;
use App\Models\QuoteStatus;
use App\Models\SimpleProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesQuoteCustomers;
use Tests\TestCase;

/**
 * Abonos (quote_installments): el saldo de una cotización es total - anticipo
 * - abonos vigentes. Un abono anulado (soft delete) no cuenta.
 */
class QuoteInstallmentBalanceTest extends TestCase
{
    use CreatesQuoteCustomers, RefreshDatabase;

    private User $user;

    private SimpleProduct $simpleProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('sanctum.stateful')[0] ?? 'http://localhost:5173');

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

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
     * Total = 10 × $100 = $1,000.00 (sin descuento ni envío).
     *
     * @param  array<string, mixed>  $extra
     */
    private function createQuote(array $extra = []): Quote
    {
        $id = $this->postJson('/api/quotes', [
            'customer_id' => $this->quoteCustomerId(),
            ...$extra,
            'items' => [['simple_product_id' => $this->simpleProduct->id, 'quantity' => 10]],
        ])->assertCreated()->json('data.id');

        return Quote::findOrFail($id);
    }

    private function addInstallment(Quote $quote, float $amount, string $date = '2026-11-10'): QuoteInstallment
    {
        return QuoteInstallment::create([
            'quote_id' => $quote->id,
            'amount' => $amount,
            'payment_date' => $date,
            'created_by' => $this->user->id,
        ]);
    }

    public function test_balance_subtracts_down_payment_and_installments(): void
    {
        $quote = $this->createQuote(['down_payment' => 200]);
        $this->addInstallment($quote, 150.50);
        $this->addInstallment($quote, 99.50);

        $this->assertSame(250.00, $quote->fresh()->installmentsTotal());
        $this->assertSame(550.00, $quote->fresh()->balance());
    }

    public function test_a_voided_installment_does_not_count(): void
    {
        $quote = $this->createQuote(['down_payment' => 200]);
        $this->addInstallment($quote, 300);
        $this->addInstallment($quote, 100)->delete();

        $this->assertSoftDeleted('quote_installments', ['amount' => 100]);
        $this->assertSame(300.00, $quote->fresh()->installmentsTotal());
        $this->assertSame(500.00, $quote->fresh()->balance());

        $this->getJson("/api/quotes/{$quote->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.installments')
            ->assertJsonPath('data.installments_total', '300.00')
            ->assertJsonPath('data.balance', '500.00');
    }

    public function test_a_quote_without_installments_keeps_its_usual_balance(): void
    {
        $quote = $this->createQuote(['down_payment' => 200, 'shipping_cost' => 50, 'discount' => 100]);

        // 1,000 - 100 + 50 = 950 de total; 950 - 200 de anticipo.
        $this->assertSame(0.00, $quote->fresh()->installmentsTotal());
        $this->assertSame(750.00, $quote->fresh()->balance());

        $this->getJson("/api/quotes/{$quote->id}")
            ->assertOk()
            ->assertJsonPath('data.installments', [])
            ->assertJsonPath('data.installments_total', '0.00')
            ->assertJsonPath('data.balance', '750.00');
    }

    public function test_show_exposes_installments_their_total_and_the_balance(): void
    {
        $quote = $this->createQuote(['down_payment' => 100]);
        $later = $this->addInstallment($quote, 200, '2026-11-12');
        $earlier = $this->addInstallment($quote, 50.25, '2026-11-05');
        QuoteInstallment::whereKey($earlier->id)->update(['notes' => 'Transferencia']);

        $this->getJson("/api/quotes/{$quote->id}")
            ->assertOk()
            ->assertJsonPath('data.installments_total', '250.25')
            ->assertJsonPath('data.balance', '649.75')
            // Del más antiguo al más reciente, por payment_date.
            ->assertJsonPath('data.installments.0.id', $earlier->id)
            ->assertJsonPath('data.installments.0.amount', '50.25')
            ->assertJsonPath('data.installments.0.payment_date', '2026-11-05')
            ->assertJsonPath('data.installments.0.notes', 'Transferencia')
            ->assertJsonPath('data.installments.0.created_by', $this->user->id)
            ->assertJsonPath('data.installments.1.id', $later->id);
    }

    public function test_index_exposes_the_balance_with_installments(): void
    {
        $withInstallments = $this->createQuote(['down_payment' => 100]);
        $this->addInstallment($withInstallments, 400);
        $this->addInstallment($withInstallments, 50)->delete();
        $withoutInstallments = $this->createQuote();

        $quotes = collect($this->getJson('/api/quotes?with=quoteStatus')->assertOk()->json('data'))->keyBy('id');

        $this->assertSame('400.00', $quotes[$withInstallments->id]['installments_total']);
        $this->assertSame('500.00', $quotes[$withInstallments->id]['balance']);
        $this->assertSame('0.00', $quotes[$withoutInstallments->id]['installments_total']);
        $this->assertSame('1000.00', $quotes[$withoutInstallments->id]['balance']);
        // Sin ?with=installments el listado no trae los abonos, solo su suma.
        $this->assertArrayNotHasKey('installments', $quotes[$withInstallments->id]);
    }

    public function test_index_does_not_query_installments_per_quote(): void
    {
        $countQueries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/quotes?with=customer,quoteStatus,comprobante')->assertOk();
            DB::disableQueryLog();

            return collect(DB::getQueryLog())
                ->filter(fn (array $query) => str_contains($query['query'], 'quote_installments'))
                ->count();
        };

        $first = $this->createQuote();
        $this->addInstallment($first, 100);
        $withOneQuote = $countQueries();

        foreach (range(1, 4) as $i) {
            $this->addInstallment($this->createQuote(), 10 * $i);
        }
        $withFiveQuotes = $countQueries();

        // Una sola subconsulta (withSum) sin importar cuántas cotizaciones haya.
        $this->assertSame(1, $withOneQuote);
        $this->assertSame($withOneQuote, $withFiveQuotes);
    }

    public function test_index_can_include_the_installments(): void
    {
        $quote = $this->createQuote();
        $this->addInstallment($quote, 100);

        $this->getJson('/api/quotes?with=installments,quoteStatus')
            ->assertOk()
            ->assertJsonPath('data.0.installments.0.amount', '100.00')
            ->assertJsonPath('data.0.balance', '900.00');
    }

    public function test_update_cancel_and_mark_paid_responses_include_installments(): void
    {
        $quote = $this->createQuote(['down_payment' => 100]);
        $this->addInstallment($quote, 300);

        $this->putJson("/api/quotes/{$quote->id}", [
            'items' => [['simple_product_id' => $this->simpleProduct->id, 'quantity' => 10]],
        ])->assertOk()
            ->assertJsonCount(1, 'data.installments')
            ->assertJsonPath('data.balance', '600.00');

        $this->patchJson("/api/quotes/{$quote->id}/mark-paid")
            ->assertOk()
            ->assertJsonCount(1, 'data.installments')
            ->assertJsonPath('data.balance', '600.00');

        // Con abonos vigentes no se puede cancelar (QuoteInstallmentApiTest):
        // la respuesta trae la relación cargada, vacía.
        $other = $this->createQuote();

        $this->patchJson("/api/quotes/{$other->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.installments', [])
            ->assertJsonPath('data.balance', '1000.00');
    }
}
