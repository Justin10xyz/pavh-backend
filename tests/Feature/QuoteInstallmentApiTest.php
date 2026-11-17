<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Quote;
use App\Models\QuoteStatus;
use App\Models\SimpleProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * POST/DELETE /api/quotes/{quote}/installments: registrar y anular abonos de
 * una cotización en Borrador, y los guards de cancelar/eliminar con abonos.
 */
class QuoteInstallmentApiTest extends TestCase
{
    use RefreshDatabase;

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
     * Total = 10 × $100 = $1,000.00; con el anticipo de $200, saldo $800.00.
     */
    private function createQuote(float $downPayment = 200): Quote
    {
        $id = $this->postJson('/api/quotes', [
            'down_payment' => $downPayment,
            'items' => [['simple_product_id' => $this->simpleProduct->id, 'quantity' => 10]],
        ])->assertCreated()->json('data.id');

        return Quote::findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function postInstallment(Quote $quote, array $overrides = []): TestResponse
    {
        return $this->postJson("/api/quotes/{$quote->id}/installments", [
            'amount' => 300,
            'payment_date' => now()->toDateString(),
            ...$overrides,
        ]);
    }

    public function test_it_registers_an_installment_and_returns_the_new_balance(): void
    {
        $quote = $this->createQuote();

        $response = $this->postInstallment($quote, ['amount' => 300.50, 'notes' => 'Transferencia'])
            ->assertCreated()
            ->assertJsonPath('data.amount', '300.50')
            ->assertJsonPath('data.payment_date', now()->toDateString())
            ->assertJsonPath('data.notes', 'Transferencia')
            ->assertJsonPath('data.created_by', $this->user->id)
            ->assertJsonPath('quote.id', $quote->id)
            ->assertJsonPath('quote.installments_total', '300.50')
            ->assertJsonPath('quote.balance', '499.50');

        $this->assertDatabaseHas('quote_installments', [
            'id' => $response->json('data.id'),
            'quote_id' => $quote->id,
            'created_by' => $this->user->id,
        ]);

        $this->postInstallment($quote, ['amount' => 99.50])
            ->assertCreated()
            ->assertJsonPath('quote.installments_total', '400.00')
            ->assertJsonPath('quote.balance', '400.00');

        $this->getJson("/api/quotes/{$quote->id}")->assertJsonPath('data.balance', '400.00');
    }

    public function test_an_installment_can_settle_the_exact_balance(): void
    {
        $quote = $this->createQuote();

        $this->postInstallment($quote, ['amount' => 800])
            ->assertCreated()
            ->assertJsonPath('quote.balance', '0.00');
    }

    public function test_it_rejects_an_installment_greater_than_the_balance(): void
    {
        $quote = $this->createQuote();

        $this->postInstallment($quote, ['amount' => 800.01])
            ->assertUnprocessable()
            ->assertJsonMissingValidationErrors()
            ->assertJsonPath('message', 'El abono ($800.01) no puede ser mayor que el saldo pendiente ($800.00).');

        $this->assertDatabaseCount('quote_installments', 0);
    }

    public function test_it_rejects_any_installment_when_there_is_no_pending_balance(): void
    {
        // Anticipo mayor que el total: saldo negativo.
        $quote = $this->createQuote(downPayment: 1200);

        $this->postInstallment($quote, ['amount' => 1])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Esta cotización no tiene saldo pendiente; no se pueden registrar abonos.');

        $this->assertDatabaseCount('quote_installments', 0);
    }

    public function test_it_rejects_a_zero_or_negative_amount(): void
    {
        $quote = $this->createQuote();

        foreach ([0, -50] as $amount) {
            $this->postInstallment($quote, ['amount' => $amount])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['amount']);
        }

        $this->postJson("/api/quotes/{$quote->id}/installments", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount', 'payment_date']);

        $this->assertDatabaseCount('quote_installments', 0);
    }

    public function test_it_rejects_a_future_payment_date(): void
    {
        $quote = $this->createQuote();

        $this->postInstallment($quote, ['payment_date' => now()->addDay()->toDateString()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_date']);

        $this->postInstallment($quote, ['payment_date' => now()->subDays(3)->toDateString()])
            ->assertCreated();
    }

    public function test_it_rejects_notes_longer_than_255_characters(): void
    {
        $quote = $this->createQuote();

        $this->postInstallment($quote, ['notes' => str_repeat('a', 256)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['notes']);
    }

    public function test_it_rejects_installments_on_a_paid_quote(): void
    {
        $quote = $this->createQuote();
        $this->patchJson("/api/quotes/{$quote->id}/mark-paid")->assertOk();

        $this->postInstallment($quote)
            ->assertUnprocessable()
            ->assertJsonMissingValidationErrors()
            ->assertJsonPath('message', 'Esta cotización ya fue pagada y no se le pueden registrar abonos.');

        $this->assertDatabaseCount('quote_installments', 0);
    }

    public function test_it_rejects_installments_on_a_cancelled_quote(): void
    {
        $quote = $this->createQuote();
        $this->patchJson("/api/quotes/{$quote->id}/cancel")->assertOk();

        $this->postInstallment($quote)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Esta cotización está cancelada y no se le pueden registrar abonos.');
    }

    public function test_it_voids_an_installment_and_returns_the_new_balance(): void
    {
        $quote = $this->createQuote();
        $kept = $this->postInstallment($quote, ['amount' => 100])->json('data.id');
        $voided = $this->postInstallment($quote, ['amount' => 250])->json('data.id');

        $this->deleteJson("/api/quotes/{$quote->id}/installments/{$voided}")
            ->assertOk()
            ->assertJsonPath('quote.id', $quote->id)
            ->assertJsonPath('quote.installments_total', '100.00')
            ->assertJsonPath('quote.balance', '700.00');

        $this->assertSoftDeleted('quote_installments', ['id' => $voided]);
        $this->assertNotSoftDeleted('quote_installments', ['id' => $kept]);

        // Ya anulado: no se encuentra otra vez.
        $this->deleteJson("/api/quotes/{$quote->id}/installments/{$voided}")->assertNotFound();
    }

    public function test_it_does_not_void_an_installment_of_a_paid_quote(): void
    {
        $quote = $this->createQuote();
        $installmentId = $this->postInstallment($quote)->json('data.id');
        $this->patchJson("/api/quotes/{$quote->id}/mark-paid")->assertOk();

        $this->deleteJson("/api/quotes/{$quote->id}/installments/{$installmentId}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Esta cotización ya fue pagada y no se le pueden anular abonos.');

        $this->assertNotSoftDeleted('quote_installments', ['id' => $installmentId]);
    }

    public function test_an_installment_of_another_quote_is_not_found(): void
    {
        $quote = $this->createQuote();
        $other = $this->createQuote();
        $otherInstallmentId = $this->postInstallment($other)->json('data.id');

        $this->deleteJson("/api/quotes/{$quote->id}/installments/{$otherInstallmentId}")->assertNotFound();

        $this->assertNotSoftDeleted('quote_installments', ['id' => $otherInstallmentId]);
    }

    public function test_cancel_is_blocked_with_installments_and_allowed_after_voiding_them(): void
    {
        $quote = $this->createQuote();
        $installmentId = $this->postInstallment($quote)->json('data.id');

        $this->patchJson("/api/quotes/{$quote->id}/cancel")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Esta cotización tiene abonos registrados; anúlalos antes de cancelarla.');

        $this->deleteJson("/api/quotes/{$quote->id}/installments/{$installmentId}")->assertOk();

        $this->patchJson("/api/quotes/{$quote->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'Cancelada');
    }

    public function test_delete_is_blocked_with_installments_and_allowed_after_voiding_them(): void
    {
        $quote = $this->createQuote();
        $installmentId = $this->postInstallment($quote)->json('data.id');

        $this->deleteJson("/api/quotes/{$quote->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Esta cotización tiene abonos registrados; anúlalos antes de eliminarla.');

        $this->assertNotSoftDeleted('quotes', ['id' => $quote->id]);

        $this->deleteJson("/api/quotes/{$quote->id}/installments/{$installmentId}")->assertOk();

        $this->deleteJson("/api/quotes/{$quote->id}")->assertNoContent();
        $this->assertSoftDeleted('quotes', ['id' => $quote->id]);
    }

    public function test_it_requires_authentication(): void
    {
        $quote = $this->createQuote();
        $installmentId = $this->postInstallment($quote)->json('data.id');

        $this->app['auth']->forgetGuards();

        $this->postInstallment($quote)->assertUnauthorized();
        $this->deleteJson("/api/quotes/{$quote->id}/installments/{$installmentId}")->assertUnauthorized();

        $this->assertDatabaseCount('quote_installments', 1);
    }
}
