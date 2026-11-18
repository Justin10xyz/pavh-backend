<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\QuoteStatus;
use App\Models\SimpleProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesQuoteCustomers;
use Tests\TestCase;

/**
 * Todo presupuesto lleva cliente: obligatorio al crear y al editar, y un
 * Borrador anterior a la regla (sin cliente) no se puede guardar ni marcar
 * como pagado sin asignarle uno.
 */
class QuoteCustomerRequiredTest extends TestCase
{
    use CreatesQuoteCustomers, RefreshDatabase;

    private const REQUIRED_MESSAGE = 'Selecciona un cliente para el presupuesto.';

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
            'stock_quantity' => 20,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function items(int $quantity = 2): array
    {
        return [['simple_product_id' => $this->simpleProduct->id, 'quantity' => $quantity]];
    }

    /**
     * Borrador de antes de que el cliente fuera obligatorio: ya no se puede
     * crear por la API.
     */
    private function legacyDraftWithoutCustomer(): Quote
    {
        $quote = Quote::create([
            'folio' => 'COT-0900',
            'quote_status_id' => QuoteStatus::where('name', 'Borrador')->value('id'),
            'customer_id' => null,
            'subtotal' => 200,
            'total' => 200,
        ]);

        QuoteItem::create([
            'quote_id' => $quote->id,
            'simple_product_id' => $this->simpleProduct->id,
            'quantity' => 2,
            'unit_price' => 100,
            'line_total' => 200,
            'price_type' => 'retail',
        ]);

        return $quote;
    }

    public function test_store_requires_a_customer(): void
    {
        $this->postJson('/api/quotes', ['items' => $this->items()])
            ->assertUnprocessable()
            ->assertJsonPath('errors.customer_id.0', self::REQUIRED_MESSAGE);

        $this->postJson('/api/quotes', ['customer_id' => null, 'items' => $this->items()])
            ->assertUnprocessable()
            ->assertJsonPath('errors.customer_id.0', self::REQUIRED_MESSAGE);

        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_store_rejects_a_soft_deleted_customer(): void
    {
        $customer = Customer::create(['name' => 'Juan Perez']);
        $customer->delete();

        $this->postJson('/api/quotes', ['customer_id' => $customer->id, 'items' => $this->items()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_id']);

        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_update_rejects_a_null_customer(): void
    {
        $quoteId = $this->postJson('/api/quotes', ['customer_id' => $this->quoteCustomerId(), 'items' => $this->items()])
            ->assertCreated()
            ->json('data.id');

        $this->putJson("/api/quotes/{$quoteId}", ['customer_id' => null, 'items' => $this->items()])
            ->assertUnprocessable()
            ->assertJsonPath('errors.customer_id.0', self::REQUIRED_MESSAGE);

        $this->assertDatabaseHas('quotes', ['id' => $quoteId, 'customer_id' => $this->quoteCustomerId()]);
    }

    public function test_update_without_the_key_keeps_the_current_customer(): void
    {
        $quoteId = $this->postJson('/api/quotes', ['customer_id' => $this->quoteCustomerId(), 'items' => $this->items()])
            ->assertCreated()
            ->json('data.id');

        $this->putJson("/api/quotes/{$quoteId}", ['items' => $this->items(5)])
            ->assertOk()
            ->assertJsonPath('data.customer_id', $this->quoteCustomerId());
    }

    public function test_update_of_a_legacy_draft_without_customer_requires_one(): void
    {
        $quote = $this->legacyDraftWithoutCustomer();

        $this->putJson("/api/quotes/{$quote->id}", ['items' => $this->items(5)])
            ->assertUnprocessable()
            ->assertJsonPath('message', self::REQUIRED_MESSAGE)
            ->assertJsonPath('errors.customer_id.0', self::REQUIRED_MESSAGE);

        // No se tocó nada: ni el cliente ni las líneas.
        $this->assertDatabaseHas('quotes', ['id' => $quote->id, 'customer_id' => null, 'total' => 200]);
        $this->assertDatabaseHas('quote_items', ['quote_id' => $quote->id, 'quantity' => 2]);

        // Asignándole uno, se guarda.
        $this->putJson("/api/quotes/{$quote->id}", ['customer_id' => $this->quoteCustomerId(), 'items' => $this->items(5)])
            ->assertOk()
            ->assertJsonPath('data.customer_id', $this->quoteCustomerId());
    }

    public function test_update_keeping_a_soft_deleted_customer_passes(): void
    {
        $customer = Customer::create(['name' => 'Juan Perez']);
        $quoteId = $this->postJson('/api/quotes', ['customer_id' => $customer->id, 'items' => $this->items()])
            ->assertCreated()
            ->json('data.id');
        $customer->delete();

        $this->putJson("/api/quotes/{$quoteId}", ['customer_id' => $customer->id, 'items' => $this->items(3)])
            ->assertOk()
            ->assertJsonPath('data.customer_id', $customer->id);

        $this->putJson("/api/quotes/{$quoteId}", ['items' => $this->items(4)])
            ->assertOk()
            ->assertJsonPath('data.customer_id', $customer->id);
    }

    public function test_mark_paid_rejects_a_quote_without_customer_without_touching_stock(): void
    {
        $quote = $this->legacyDraftWithoutCustomer();

        $this->patchJson("/api/quotes/{$quote->id}/mark-paid")
            ->assertUnprocessable()
            ->assertJsonMissingValidationErrors()
            ->assertJsonPath('message', 'Asigna un cliente al presupuesto antes de marcarlo como pagado.');

        $this->assertNull($quote->fresh()->paid_at);
        $this->assertSame('Borrador', $quote->fresh()->quoteStatus->name);
        $this->assertSame(20, $this->simpleProduct->fresh()->stock_quantity);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_mark_paid_with_a_customer_still_deducts_stock(): void
    {
        $quoteId = $this->postJson('/api/quotes', ['customer_id' => $this->quoteCustomerId(), 'items' => $this->items(3)])
            ->assertCreated()
            ->json('data.id');

        $this->patchJson("/api/quotes/{$quoteId}/mark-paid")
            ->assertOk()
            ->assertJsonPath('data.status', 'Pagada');

        $this->assertSame(17, $this->simpleProduct->fresh()->stock_quantity);
    }
}
