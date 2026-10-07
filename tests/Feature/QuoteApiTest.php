<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quote;
use App\Models\QuoteStatus;
use App\Models\Supplier;
use App\Models\UnitType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuoteApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('sanctum.stateful')[0] ?? 'http://localhost:5173');

        $this->actingAs(User::factory()->create());

        foreach (['Borrador', 'Convertida', 'Cancelada'] as $name) {
            QuoteStatus::create(['name' => $name]);
        }
    }

    private function createVariant(string $color, float $pricePerM2): ProductVariant
    {
        $supplier = Supplier::create(['name' => 'Interceramic']);
        $category = Category::create(['name' => 'Floor', 'code_prefix' => 'PIS']);
        $unitType = UnitType::create(['name' => 'm2']);

        $product = Product::create([
            'supplier_id' => $supplier->id,
            'category_id' => $category->id,
            'unit_type_id' => $unitType->id,
            'name' => 'Creato',
            'purchase_unit' => 'caja',
        ]);

        return ProductVariant::create([
            'product_id' => $product->id,
            'code' => "PIS-CREATO-{$color}-60X120",
            'color' => $color,
            'size' => '60x120',
            'price_per_m2' => $pricePerM2,
            'price_per_box' => $pricePerM2 * 1.44,
        ]);
    }

    public function test_it_creates_a_quote_ignoring_client_sent_unit_price(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);
        $customer = Customer::create(['name' => 'Juan Perez']);

        $response = $this->postJson('/api/quotes', [
            'customer_id' => $customer->id,
            'notes' => 'Entrega urgente',
            'items' => [
                ['product_variant_id' => $variant->id, 'quantity' => 2, 'unit_price' => 999999],
            ],
        ])->assertCreated();

        $response->assertJsonPath('data.folio', 'COT-0001')
            ->assertJsonPath('data.status', 'Borrador')
            ->assertJsonPath('data.items.0.unit_price', '100.00')
            ->assertJsonPath('data.items.0.line_total', '200.00')
            ->assertJsonPath('data.subtotal', '200.00')
            ->assertJsonPath('data.total', '200.00');

        $this->assertDatabaseHas('quote_items', [
            'quote_id' => $response->json('data.id'),
            'unit_price' => 100.00,
            'line_total' => 200.00,
        ]);
    }

    public function test_it_calculates_subtotal_and_total_from_multiple_lines(): void
    {
        $variantA = $this->createVariant('Taupe', 100.00);
        $variantB = $this->createVariant('Gris', 250.50);

        $response = $this->postJson('/api/quotes', [
            'items' => [
                ['product_variant_id' => $variantA->id, 'quantity' => 3],
                ['product_variant_id' => $variantB->id, 'quantity' => 2],
            ],
        ])->assertCreated();

        // 3 * 100.00 = 300.00, 2 * 250.50 = 501.00 -> subtotal 801.00
        $response->assertJsonPath('data.subtotal', '801.00')
            ->assertJsonPath('data.total', '801.00');
    }

    public function test_it_generates_unique_sequential_folios(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);

        $first = $this->postJson('/api/quotes', [
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
        ])->assertCreated();

        $second = $this->postJson('/api/quotes', [
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
        ])->assertCreated();

        $first->assertJsonPath('data.folio', 'COT-0001');
        $second->assertJsonPath('data.folio', 'COT-0002');
    }

    public function test_it_updates_a_quote_in_draft_status(): void
    {
        $variantA = $this->createVariant('Taupe', 100.00);
        $variantB = $this->createVariant('Gris', 200.00);

        $created = $this->postJson('/api/quotes', [
            'items' => [['product_variant_id' => $variantA->id, 'quantity' => 1]],
        ])->assertCreated();

        $quoteId = $created->json('data.id');

        $this->putJson("/api/quotes/{$quoteId}", [
            'notes' => 'Actualizada',
            'items' => [['product_variant_id' => $variantB->id, 'quantity' => 4]],
        ])->assertOk()
            ->assertJsonPath('data.notes', 'Actualizada')
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.product_variant_id', $variantB->id)
            ->assertJsonPath('data.subtotal', '800.00');

        $this->assertDatabaseCount('quote_items', 1);
    }

    public function test_it_rejects_updating_a_quote_that_is_not_in_draft_status(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);

        $created = $this->postJson('/api/quotes', [
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
        ])->assertCreated();

        $quoteId = $created->json('data.id');

        Quote::find($quoteId)->update([
            'quote_status_id' => QuoteStatus::where('name', 'Convertida')->first()->id,
        ]);

        $this->putJson("/api/quotes/{$quoteId}", [
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 5]],
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Solo se pueden editar cotizaciones en estado Borrador.');

        $this->assertDatabaseHas('quote_items', [
            'quote_id' => $quoteId,
            'quantity' => 1,
        ]);
    }

    public function test_it_requires_at_least_one_item(): void
    {
        $this->postJson('/api/quotes', ['items' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');
    }

    public function test_it_shows_a_quote_with_items_customer_and_status(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);
        $customer = Customer::create(['name' => 'Juan Perez']);

        $created = $this->postJson('/api/quotes', [
            'customer_id' => $customer->id,
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
        ])->assertCreated();

        $this->getJson("/api/quotes/{$created->json('data.id')}")
            ->assertOk()
            ->assertJsonPath('data.customer.name', 'Juan Perez')
            ->assertJsonPath('data.status', 'Borrador')
            ->assertJsonCount(1, 'data.items');
    }

    public function test_index_with_customer_and_quote_status_eager_loads_both_relations(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);
        $customer = Customer::create(['name' => 'Juan Perez']);

        $this->postJson('/api/quotes', [
            'customer_id' => $customer->id,
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
        ])->assertCreated();

        $this->getJson('/api/quotes?with=customer,quoteStatus')
            ->assertOk()
            ->assertJsonPath('data.0.customer.name', 'Juan Perez')
            ->assertJsonPath('data.0.status', 'Borrador');
    }

    public function test_index_ignores_unknown_with_values(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);

        $this->postJson('/api/quotes', [
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
        ])->assertCreated();

        $this->getJson('/api/quotes?with=customer,notARealRelation')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'Borrador');
    }

    public function test_it_soft_deletes_a_quote_and_its_items(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);

        $created = $this->postJson('/api/quotes', [
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
        ])->assertCreated();

        $quoteId = $created->json('data.id');

        $this->deleteJson("/api/quotes/{$quoteId}")->assertNoContent();

        $this->assertSoftDeleted('quotes', ['id' => $quoteId]);
        $this->assertDatabaseCount('quote_items', 0);
    }
}
