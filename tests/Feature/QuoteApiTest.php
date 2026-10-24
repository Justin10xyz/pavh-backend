<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quote;
use App\Models\QuoteStatus;
use App\Models\Sale;
use App\Models\SimpleProduct;
use App\Models\Supplier;
use App\Models\UnitType;
use App\Models\User;
use App\Services\QuotePdfGenerator;
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

        foreach (['Borrador', 'Pagada', 'Convertida', 'Cancelada'] as $name) {
            QuoteStatus::create(['name' => $name]);
        }
    }

    // m2_per_box=1.0 por defecto: estos tests prueban la lógica de cotización,
    // no la conversión, y así N cajas = N m². La conversión real (1.44) tiene
    // sus propios tests.
    private function createVariant(string $color, float $pricePerM2, ?float $m2PerBox = 1.0): ProductVariant
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
            'price_per_box' => $pricePerM2 * ($m2PerBox ?? 1),
            'm2_per_box' => $m2PerBox,
        ]);
    }

    private function createSimpleProduct(float $price = 189.50, int $stockQuantity = 20): SimpleProduct
    {
        $category = Category::create(['name' => 'Materiales', 'code_prefix' => 'MAT', 'product_form_type' => 'simple']);

        return SimpleProduct::create([
            'category_id' => $category->id,
            'name' => 'Pegazulejo gris 20kg',
            'price' => $price,
            'stock_quantity' => $stockQuantity,
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
                ['product_variant_id' => $variant->id, 'boxes' => 2, 'unit_price' => 999999],
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
                ['product_variant_id' => $variantA->id, 'boxes' => 3],
                ['product_variant_id' => $variantB->id, 'boxes' => 2],
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
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 1]],
        ])->assertCreated();

        $second = $this->postJson('/api/quotes', [
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 1]],
        ])->assertCreated();

        $first->assertJsonPath('data.folio', 'COT-0001');
        $second->assertJsonPath('data.folio', 'COT-0002');
    }

    public function test_it_updates_a_quote_in_draft_status(): void
    {
        $variantA = $this->createVariant('Taupe', 100.00);
        $variantB = $this->createVariant('Gris', 200.00);

        $created = $this->postJson('/api/quotes', [
            'items' => [['product_variant_id' => $variantA->id, 'boxes' => 1]],
        ])->assertCreated();

        $quoteId = $created->json('data.id');

        $this->putJson("/api/quotes/{$quoteId}", [
            'notes' => 'Actualizada',
            'items' => [['product_variant_id' => $variantB->id, 'boxes' => 4]],
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
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 1]],
        ])->assertCreated();

        $quoteId = $created->json('data.id');

        Quote::find($quoteId)->update([
            'quote_status_id' => QuoteStatus::where('name', 'Convertida')->first()->id,
        ]);

        $this->putJson("/api/quotes/{$quoteId}", [
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 5]],
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
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 1]],
        ])->assertCreated();

        $this->getJson("/api/quotes/{$created->json('data.id')}")
            ->assertOk()
            ->assertJsonPath('data.customer.name', 'Juan Perez')
            ->assertJsonPath('data.status', 'Borrador')
            ->assertJsonCount(1, 'data.items');
    }

    public function test_it_shows_the_sale_a_converted_quote_was_converted_into(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);

        $quoteId = $this->postJson('/api/quotes', [
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 1]],
        ])->assertCreated()->json('data.id');

        Quote::find($quoteId)->update([
            'quote_status_id' => QuoteStatus::where('name', 'Convertida')->first()->id,
        ]);
        $sale = Sale::create(['folio' => 'VEN-0001', 'quote_id' => $quoteId, 'subtotal' => 100, 'total' => 100]);

        $this->getJson("/api/quotes/{$quoteId}")
            ->assertOk()
            ->assertJsonPath('data.sale.id', $sale->id)
            ->assertJsonPath('data.sale.folio', 'VEN-0001');
    }

    public function test_it_shows_a_null_sale_for_a_quote_not_converted(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);

        $quoteId = $this->postJson('/api/quotes', [
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 1]],
        ])->assertCreated()->json('data.id');

        $this->getJson("/api/quotes/{$quoteId}")
            ->assertOk()
            ->assertJsonPath('data.sale', null);
    }

    public function test_it_shows_the_sale_of_a_converted_quote_even_if_the_sale_was_soft_deleted(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);

        $quoteId = $this->postJson('/api/quotes', [
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 1]],
        ])->assertCreated()->json('data.id');

        $sale = Sale::create(['folio' => 'VEN-0001', 'quote_id' => $quoteId, 'subtotal' => 100, 'total' => 100]);
        $sale->delete();

        $this->getJson("/api/quotes/{$quoteId}")
            ->assertOk()
            ->assertJsonPath('data.sale.folio', 'VEN-0001');
    }

    public function test_index_does_not_include_the_sale_relation(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);

        $quoteId = $this->postJson('/api/quotes', [
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 1]],
        ])->assertCreated()->json('data.id');

        Sale::create(['folio' => 'VEN-0001', 'quote_id' => $quoteId, 'subtotal' => 100, 'total' => 100]);

        $this->getJson('/api/quotes')
            ->assertOk()
            ->assertJsonMissingPath('data.0.sale');
    }

    public function test_it_shows_a_quote_whose_customer_was_soft_deleted(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);
        $customer = Customer::create(['name' => 'Juan Perez']);

        $created = $this->postJson('/api/quotes', [
            'customer_id' => $customer->id,
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 1]],
        ])->assertCreated();

        $customer->delete();

        $this->getJson("/api/quotes/{$created->json('data.id')}")
            ->assertOk()
            ->assertJsonPath('data.customer.name', 'Juan Perez');

        $this->getJson('/api/quotes?with=customer')
            ->assertOk()
            ->assertJsonPath('data.0.customer.name', 'Juan Perez');
    }

    public function test_it_rejects_creating_a_quote_for_a_soft_deleted_customer(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);
        $customer = Customer::create(['name' => 'Juan Perez']);
        $customer->delete();

        $this->postJson('/api/quotes', [
            'customer_id' => $customer->id,
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 1]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('customer_id');

        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_it_rejects_creating_a_quote_with_soft_deleted_variant_and_simple_product(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);
        $simpleProduct = $this->createSimpleProduct();
        $variant->delete();
        $simpleProduct->delete();

        $this->postJson('/api/quotes', [
            'items' => [
                ['product_variant_id' => $variant->id, 'boxes' => 1],
                ['simple_product_id' => $simpleProduct->id, 'quantity' => 2],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.product_variant_id', 'items.1.simple_product_id']);

        $this->assertDatabaseCount('quotes', 0);
        $this->assertDatabaseCount('quote_items', 0);
    }

    public function test_it_rejects_updating_a_quote_with_soft_deleted_variant_and_simple_product(): void
    {
        $activeVariant = $this->createVariant('Taupe', 100.00);

        $quoteId = $this->postJson('/api/quotes', [
            'items' => [['product_variant_id' => $activeVariant->id, 'boxes' => 1]],
        ])->assertCreated()->json('data.id');

        $deletedVariant = $this->createVariant('Gris', 200.00);
        $deletedSimpleProduct = $this->createSimpleProduct();
        $deletedVariant->delete();
        $deletedSimpleProduct->delete();

        $this->putJson("/api/quotes/{$quoteId}", [
            'items' => [
                ['product_variant_id' => $deletedVariant->id, 'boxes' => 1],
                ['simple_product_id' => $deletedSimpleProduct->id, 'quantity' => 2],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.product_variant_id', 'items.1.simple_product_id']);

        // Las líneas originales quedan intactas.
        $this->assertDatabaseCount('quote_items', 1);
        $this->assertDatabaseHas('quote_items', ['quote_id' => $quoteId, 'product_variant_id' => $activeVariant->id]);
    }

    public function test_it_rejects_updating_a_quote_to_a_soft_deleted_customer(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);
        $customer = Customer::create(['name' => 'Juan Perez']);
        $customer->delete();

        $quoteId = $this->postJson('/api/quotes', [
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 1]],
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/quotes/{$quoteId}", [
            'customer_id' => $customer->id,
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 1]],
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'El cliente seleccionado ya no está disponible.');

        $this->assertDatabaseHas('quotes', ['id' => $quoteId, 'customer_id' => null]);
    }

    public function test_it_updates_a_quote_keeping_its_soft_deleted_customer(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);
        $customer = Customer::create(['name' => 'Juan Perez']);

        $quoteId = $this->postJson('/api/quotes', [
            'customer_id' => $customer->id,
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 1]],
        ])->assertCreated()->json('data.id');

        $customer->delete();

        // El form re-envía el mismo customer_id: no es un cambio, no se bloquea.
        $this->putJson("/api/quotes/{$quoteId}", [
            'customer_id' => $customer->id,
            'notes' => 'Actualizada',
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 4]],
        ])->assertOk()
            ->assertJsonPath('data.customer_id', $customer->id)
            ->assertJsonPath('data.notes', 'Actualizada')
            ->assertJsonPath('data.subtotal', '400.00');

        $this->assertDatabaseHas('quotes', ['id' => $quoteId, 'customer_id' => $customer->id]);
    }

    public function test_it_rejects_changing_a_quote_customer_to_a_different_soft_deleted_customer(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);
        $current = Customer::create(['name' => 'Juan Perez']);
        $deleted = Customer::create(['name' => 'Maria Lopez']);
        $deleted->delete();

        $quoteId = $this->postJson('/api/quotes', [
            'customer_id' => $current->id,
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 1]],
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/quotes/{$quoteId}", [
            'customer_id' => $deleted->id,
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 4]],
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'El cliente seleccionado ya no está disponible.');

        // Ni el cliente ni las líneas cambian.
        $this->assertDatabaseHas('quotes', ['id' => $quoteId, 'customer_id' => $current->id]);
        $this->assertDatabaseHas('quote_items', ['quote_id' => $quoteId, 'quantity' => 1]);
    }

    public function test_index_with_customer_and_quote_status_eager_loads_both_relations(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);
        $customer = Customer::create(['name' => 'Juan Perez']);

        $this->postJson('/api/quotes', [
            'customer_id' => $customer->id,
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 1]],
        ])->assertCreated();

        $this->getJson('/api/quotes?with=customer,quoteStatus')
            ->assertOk()
            ->assertJsonPath('data.0.customer.name', 'Juan Perez')
            ->assertJsonPath('data.0.status', 'Borrador');
    }

    public function test_index_returns_quotes_newest_first(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);

        $ids = [];
        foreach (['2026-09-01 10:00:00', '2026-10-05 10:00:00', '2026-09-15 10:00:00'] as $createdAt) {
            $ids[] = $this->travelTo($createdAt, fn () => $this->postJson('/api/quotes', [
                'items' => [['product_variant_id' => $variant->id, 'boxes' => 1]],
            ])->assertCreated()->json('data.id'));
        }

        $this->getJson('/api/quotes')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.id', $ids[1])
            ->assertJsonPath('data.1.id', $ids[2])
            ->assertJsonPath('data.2.id', $ids[0]);
    }

    public function test_index_filters_by_customer(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);
        $juan = Customer::create(['name' => 'Juan Perez']);
        $maria = Customer::create(['name' => 'Maria Lopez']);

        foreach ([$juan, $maria, $juan, null] as $customer) {
            $this->postJson('/api/quotes', [
                'customer_id' => $customer?->id,
                'items' => [['product_variant_id' => $variant->id, 'boxes' => 1]],
            ])->assertCreated();
        }

        $this->getJson("/api/quotes?customer_id={$juan->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.customer_id', $juan->id)
            ->assertJsonPath('data.1.customer_id', $juan->id);
    }

    public function test_index_filters_by_soft_deleted_customer(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);
        $juan = Customer::create(['name' => 'Juan Perez']);

        $this->postJson('/api/quotes', [
            'customer_id' => $juan->id,
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 1]],
        ])->assertCreated();

        $juan->delete();

        $this->getJson("/api/quotes?customer_id={$juan->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_index_rejects_non_integer_customer_id(): void
    {
        $this->getJson('/api/quotes?customer_id=abc')
            ->assertStatus(422)
            ->assertJsonValidationErrors('customer_id');
    }

    public function test_index_ignores_unknown_with_values(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);

        $this->postJson('/api/quotes', [
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 1]],
        ])->assertCreated();

        $this->getJson('/api/quotes?with=customer,notARealRelation')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'Borrador');
    }

    public function test_it_soft_deletes_a_quote_keeping_its_items(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);

        $created = $this->postJson('/api/quotes', [
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 1]],
        ])->assertCreated();

        $quoteId = $created->json('data.id');

        $this->deleteJson("/api/quotes/{$quoteId}")->assertNoContent();

        $this->assertSoftDeleted('quotes', ['id' => $quoteId]);
        $this->assertDatabaseCount('quote_items', 1);

        $this->getJson("/api/quotes/{$quoteId}")->assertNotFound();

        Quote::withTrashed()->findOrFail($quoteId)->restore();

        $this->assertCount(1, Quote::findOrFail($quoteId)->items);
    }

    public function test_a_cancelled_quote_can_be_deleted(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);

        $quoteId = $this->postJson('/api/quotes', [
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 1]],
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/quotes/{$quoteId}/cancel")->assertOk();

        $this->deleteJson("/api/quotes/{$quoteId}")->assertNoContent();

        $this->assertSoftDeleted('quotes', ['id' => $quoteId]);
    }

    public function test_a_converted_quote_cannot_be_deleted(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);

        $quoteId = $this->postJson('/api/quotes', [
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 1]],
        ])->assertCreated()->json('data.id');

        Sale::create(['folio' => 'VEN-0001', 'quote_id' => $quoteId, 'subtotal' => 100, 'total' => 100]);

        $this->deleteJson("/api/quotes/{$quoteId}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Esta cotización ya fue convertida a venta y no se puede eliminar.');

        $this->assertNotSoftDeleted('quotes', ['id' => $quoteId]);
    }

    public function test_a_quote_whose_sale_was_soft_deleted_cannot_be_deleted(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);

        $quoteId = $this->postJson('/api/quotes', [
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 1]],
        ])->assertCreated()->json('data.id');

        Sale::create(['folio' => 'VEN-0001', 'quote_id' => $quoteId, 'subtotal' => 100, 'total' => 100])->delete();

        $this->deleteJson("/api/quotes/{$quoteId}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Esta cotización ya fue convertida a venta y no se puede eliminar.');

        $this->assertNotSoftDeleted('quotes', ['id' => $quoteId]);
    }

    public function test_it_creates_a_quote_with_a_simple_product_line(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);
        $simpleProduct = $this->createSimpleProduct();

        $response = $this->postJson('/api/quotes', [
            'items' => [
                ['product_variant_id' => $variant->id, 'boxes' => 2],
                ['simple_product_id' => $simpleProduct->id, 'quantity' => 3],
            ],
        ])->assertCreated();

        $response->assertJsonPath('data.items.0.product_variant_id', $variant->id)
            ->assertJsonPath('data.items.0.simple_product_id', null)
            ->assertJsonPath('data.items.1.product_variant_id', null)
            ->assertJsonPath('data.items.1.product_variant', null)
            ->assertJsonPath('data.items.1.simple_product_id', $simpleProduct->id)
            ->assertJsonPath('data.items.1.simple_product.id', $simpleProduct->id)
            ->assertJsonPath('data.items.1.simple_product.name', 'Pegazulejo gris 20kg')
            ->assertJsonPath('data.items.1.simple_product.price', '189.50');

        $this->assertDatabaseHas('quote_items', [
            'quote_id' => $response->json('data.id'),
            'product_variant_id' => null,
            'simple_product_id' => $simpleProduct->id,
        ]);

        $this->getJson("/api/quotes/{$response->json('data.id')}")
            ->assertOk()
            ->assertJsonPath('data.items.1.simple_product.name', 'Pegazulejo gris 20kg');
    }

    public function test_it_updates_a_quote_with_a_simple_product_line(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);
        $simpleProduct = $this->createSimpleProduct();

        $quoteId = $this->postJson('/api/quotes', [
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 2]],
        ])->json('data.id');

        $this->putJson("/api/quotes/{$quoteId}", [
            'items' => [['simple_product_id' => $simpleProduct->id, 'quantity' => 4]],
        ])->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.simple_product.id', $simpleProduct->id);
    }

    public function test_it_rejects_a_quote_line_with_both_variant_and_simple_product(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);
        $simpleProduct = $this->createSimpleProduct();

        $this->postJson('/api/quotes', [
            'items' => [
                ['product_variant_id' => $variant->id, 'simple_product_id' => $simpleProduct->id, 'quantity' => 1],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.product_variant_id']);

        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_it_rejects_a_quote_line_without_variant_or_simple_product(): void
    {
        $this->postJson('/api/quotes', [
            'items' => [['quantity' => 1]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.product_variant_id', 'items.0.simple_product_id']);

        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_it_rejects_both_ids_on_quote_update(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);
        $simpleProduct = $this->createSimpleProduct();

        $quoteId = $this->postJson('/api/quotes', [
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 2]],
        ])->json('data.id');

        $this->putJson("/api/quotes/{$quoteId}", [
            'items' => [['product_variant_id' => $variant->id, 'simple_product_id' => $simpleProduct->id, 'quantity' => 1]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.product_variant_id']);
    }

    public function test_it_resolves_simple_product_line_price_server_side_and_includes_it_in_totals(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);
        $simpleProduct = $this->createSimpleProduct(price: 189.50);

        $response = $this->postJson('/api/quotes', [
            'items' => [
                ['product_variant_id' => $variant->id, 'boxes' => 2],
                ['simple_product_id' => $simpleProduct->id, 'quantity' => 3, 'unit_price' => 1],
            ],
        ])->assertCreated();

        // 2 * 100.00 + 3 * 189.50 = 200.00 + 568.50
        $response->assertJsonPath('data.items.1.unit_price', '189.50')
            ->assertJsonPath('data.items.1.line_total', '568.50')
            ->assertJsonPath('data.subtotal', '768.50')
            ->assertJsonPath('data.total', '768.50');

        $this->assertDatabaseHas('quote_items', [
            'quote_id' => $response->json('data.id'),
            'simple_product_id' => $simpleProduct->id,
            'unit_price' => 189.50,
            'line_total' => 568.50,
        ]);
    }

    public function test_it_recalculates_simple_product_line_price_on_update(): void
    {
        $simpleProduct = $this->createSimpleProduct(price: 189.50);

        $quoteId = $this->postJson('/api/quotes', [
            'items' => [['simple_product_id' => $simpleProduct->id, 'quantity' => 1]],
        ])->json('data.id');

        $simpleProduct->update(['price' => 200.00]);

        $this->putJson("/api/quotes/{$quoteId}", [
            'items' => [['simple_product_id' => $simpleProduct->id, 'quantity' => 2]],
        ])->assertOk()
            ->assertJsonPath('data.items.0.unit_price', '200.00')
            ->assertJsonPath('data.items.0.line_total', '400.00')
            ->assertJsonPath('data.subtotal', '400.00')
            ->assertJsonPath('data.total', '400.00');
    }

    public function test_it_downloads_a_quote_with_a_simple_product_line_as_pdf(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);
        $simpleProduct = $this->createSimpleProduct();

        $quoteId = $this->postJson('/api/quotes', [
            'items' => [
                ['product_variant_id' => $variant->id, 'boxes' => 2],
                ['simple_product_id' => $simpleProduct->id, 'quantity' => 3],
            ],
        ])->assertCreated()->json('data.id');

        // Soft-deleted después de cotizar: el documento se debe poder seguir reimprimiendo.
        $simpleProduct->delete();

        $response = $this->get("/api/quotes/{$quoteId}/pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertDownload('Presupuesto-COT-0001.pdf');

        $this->assertStringStartsWith('%PDF-', $response->streamedContent());
    }

    public function test_quote_pdf_shows_each_line_quantity_with_its_own_unit(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);
        $simpleProduct = $this->createSimpleProduct();

        $quoteId = $this->postJson('/api/quotes', [
            'items' => [
                ['product_variant_id' => $variant->id, 'boxes' => 3],
                ['simple_product_id' => $simpleProduct->id, 'quantity' => 3],
            ],
        ])->assertCreated()->json('data.id');

        // Captura la estructura neutral que arma el controlador (el PDF real
        // viene comprimido por dompdf y no hay parser de PDF en el proyecto).
        $captured = null;
        $this->mock(QuotePdfGenerator::class, function ($mock) use (&$captured) {
            $mock->shouldReceive('generate')->once()->andReturnUsing(function (array $quote) use (&$captured) {
                $captured = $quote;

                return '%PDF-fake';
            });
        });

        $this->get("/api/quotes/{$quoteId}/pdf")->assertOk();

        $this->assertSame('m2', $captured['items'][0]['unit']);
        $this->assertSame('uds', $captured['items'][1]['unit']);

        // La plantilla real, con esa misma estructura.
        $html = view('pdf.presupuesto', ['quote' => $captured])->render();

        $this->assertStringNotContainsString('Cant. (m²)', $html);
        $this->assertStringContainsString('>Cant.</th>', $html);
        // m2_per_box=1.0 en este helper: 3 cajas = 3 m²
        $this->assertMatchesRegularExpression('/\b3 cajas/u', $html);
        $this->assertMatchesRegularExpression('/\(3 m²\)/u', $html);
        $this->assertMatchesRegularExpression('/\b3 uds\./u', $html);
    }

    public function test_quote_pdf_totals_box_shows_only_the_amounts_that_apply(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);

        // 10 cajas × 1 m² × 100 = 1000; total guardado 900 (con descuento).
        $quoteId = $this->postJson('/api/quotes', [
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 10]],
            'discount' => 100,
            'shipping_cost' => 50,
            'down_payment' => 1200,
            'payment_terms' => 'credit',
            'credit_due_date' => '2026-11-30',
        ])->assertCreated()->json('data.id');

        $captured = null;
        $this->mock(QuotePdfGenerator::class, function ($mock) use (&$captured) {
            $mock->shouldReceive('generate')->once()->andReturnUsing(function (array $quote) use (&$captured) {
                $captured = $quote;

                return '%PDF-fake';
            });
        });

        $this->get("/api/quotes/{$quoteId}/pdf")->assertOk();

        // En el PDF el Total ya incluye el envío; el saldo es Total - Anticipo.
        $this->assertSame(950.0, $captured['total']);
        $this->assertSame(-250.0, $captured['balance']);

        $html = view('pdf.presupuesto', ['quote' => $captured])->render();

        $this->assertStringContainsString('PRESUPUESTO', $html);
        // Encabezado compartido con el Comprobante: INNOVA y, más chico, Interceramic.
        $this->assertStringContainsString('images/branding/logo-pdf-escala-grises.png', $html);
        $this->assertStringContainsString('images/branding/interceramic-logo-pdf.png', $html);
        $this->assertStringContainsString('Descuento', $html);
        $this->assertStringContainsString('Costo de envío', $html);
        $this->assertStringContainsString('Anticipo', $html);
        $this->assertStringContainsString('-$250.00', $html);
        $this->assertStringContainsString('Crédito (vence 30/11/2026)', $html);
        // Campos exclusivos del Comprobante.
        $this->assertStringNotContainsString('RFC', $html);
        $this->assertStringNotContainsString('idCIF', $html);
        $this->assertStringNotContainsString('Régimen', $html);
        $this->assertStringNotContainsString('Lugar de expedición', $html);
        $this->assertStringNotContainsString('Total con letra', $html);

        // Sin descuento, envío ni anticipo: esos renglones no aparecen.
        $html = view('pdf.presupuesto', ['quote' => [
            ...$captured,
            'discount' => 0.0,
            'shipping_cost' => 0.0,
            'down_payment' => 0.0,
            'total' => 1000.0,
            'balance' => 1000.0,
            'payment_terms' => 'cash',
            'credit_due_date' => null,
        ]])->render();

        $this->assertStringNotContainsString('Descuento', $html);
        $this->assertStringNotContainsString('Costo de envío', $html);
        $this->assertStringNotContainsString('Anticipo', $html);
        $this->assertStringContainsString('Contado', $html);
    }

    public function test_it_quotes_variant_lines_by_whole_boxes(): void
    {
        // 4 cajas × 1.44 = 5.76 m² × 359.00 = 2067.84 (lo que cubren las cajas
        // que se van a entregar, no los m² que pidió el cliente)
        $variant = $this->createVariant('Taupe', 359.00, m2PerBox: 1.44);

        $quoteId = $this->postJson('/api/quotes', [
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 4]],
        ])->assertCreated()
            ->assertJsonPath('data.items.0.boxes', 4)
            ->assertJsonPath('data.items.0.quantity', '5.760')
            ->assertJsonPath('data.items.0.line_total', '2067.84')
            ->assertJsonPath('data.total', '2067.84')
            ->json('data.id');

        // Editar recalcula igual
        $this->putJson("/api/quotes/{$quoteId}", [
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 5]],
        ])->assertOk()
            ->assertJsonPath('data.items.0.boxes', 5)
            ->assertJsonPath('data.items.0.quantity', '7.200')
            ->assertJsonPath('data.total', '2584.80');

        // convert devuelve las cajas (lo que se manda a POST /sales) y los m² de referencia
        $this->getJson("/api/quotes/{$quoteId}/convert")
            ->assertOk()
            ->assertJsonPath('data.items.0.boxes', 5)
            ->assertJsonPath('data.items.0.quantity', '7.200');
    }

    public function test_it_rejects_a_quote_variant_line_without_m2_per_box(): void
    {
        $variant = $this->createVariant('Taupe', 100.00, m2PerBox: null);

        $this->postJson('/api/quotes', [
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 2]],
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'La variante PIS-CREATO-Taupe-60X120 no tiene factor de conversión m2_per_box configurado, no se puede cotizar.');

        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_it_requires_whole_boxes_and_rejects_client_square_meters_on_quote_variant_lines(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);

        $this->postJson('/api/quotes', [
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 5]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.boxes']);

        $this->postJson('/api/quotes', [
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 1.5]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.boxes']);

        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_it_rejects_a_quote_with_fractional_quantity_on_a_simple_product_line(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);
        $simpleProduct = $this->createSimpleProduct();

        $this->postJson('/api/quotes', [
            'items' => [
                ['product_variant_id' => $variant->id, 'boxes' => 3],
                ['simple_product_id' => $simpleProduct->id, 'quantity' => 2.5],
            ],
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'La cantidad de Pegazulejo gris 20kg debe ser un número entero de unidades.');

        $this->assertDatabaseCount('quotes', 0);
        $this->assertDatabaseCount('quote_items', 0);
    }

    public function test_it_rejects_updating_a_quote_with_fractional_quantity_on_a_simple_product_line(): void
    {
        $simpleProduct = $this->createSimpleProduct(price: 189.50);

        $quoteId = $this->postJson('/api/quotes', [
            'items' => [['simple_product_id' => $simpleProduct->id, 'quantity' => 2]],
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/quotes/{$quoteId}", [
            'items' => [['simple_product_id' => $simpleProduct->id, 'quantity' => 2.5]],
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'La cantidad de Pegazulejo gris 20kg debe ser un número entero de unidades.');

        // La cotización y sus líneas quedan tal cual estaban.
        $this->assertDatabaseCount('quote_items', 1);
        $this->assertDatabaseHas('quote_items', ['quote_id' => $quoteId, 'quantity' => 2, 'line_total' => 379.00]);
        $this->assertDatabaseHas('quotes', ['id' => $quoteId, 'total' => 379.00]);
    }

    public function test_it_cancels_a_quote_in_draft_status(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);

        $quoteId = $this->postJson('/api/quotes', [
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 3]],
        ])->assertCreated()->json('data.id');

        $cancelledStatus = QuoteStatus::where('name', 'Cancelada')->first();

        $this->patchJson("/api/quotes/{$quoteId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.id', $quoteId)
            ->assertJsonPath('data.quote_status_id', $cancelledStatus->id)
            ->assertJsonPath('data.status', 'Cancelada');

        $this->assertDatabaseHas('quotes', ['id' => $quoteId, 'quote_status_id' => $cancelledStatus->id]);
        // Solo cambia el estado: las líneas siguen intactas y no se crea ninguna venta.
        $this->assertDatabaseHas('quote_items', ['quote_id' => $quoteId, 'quantity' => 3]);
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_it_rejects_cancelling_a_converted_quote(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);

        $quoteId = $this->postJson('/api/quotes', [
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 1]],
        ])->assertCreated()->json('data.id');

        $convertedStatus = QuoteStatus::where('name', 'Convertida')->first();
        Quote::find($quoteId)->update(['quote_status_id' => $convertedStatus->id]);

        $this->patchJson("/api/quotes/{$quoteId}/cancel")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Esta cotización ya fue convertida a venta y no se puede cancelar.');

        $this->assertDatabaseHas('quotes', ['id' => $quoteId, 'quote_status_id' => $convertedStatus->id]);
    }

    public function test_it_rejects_cancelling_an_already_cancelled_quote(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);

        $quoteId = $this->postJson('/api/quotes', [
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 1]],
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/quotes/{$quoteId}/cancel")->assertOk();

        $this->patchJson("/api/quotes/{$quoteId}/cancel")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Esta cotización ya está cancelada.');
    }

    public function test_it_stores_payment_fields_and_defaults_payment_terms_to_cash(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);

        $cashId = $this->postJson('/api/quotes', [
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 1]],
        ])->assertCreated()->json('data.id');

        $this->assertSame('cash', Quote::find($cashId)->payment_terms);

        $creditId = $this->postJson('/api/quotes', [
            'discount' => 50,
            'down_payment' => 100,
            'shipping_cost' => 250.5,
            'payment_terms' => 'credit',
            'credit_due_date' => '2026-11-30',
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 1]],
        ])->assertCreated()->json('data.id');

        $quote = Quote::find($creditId);
        $this->assertSame('50.00', $quote->discount);
        $this->assertSame('100.00', $quote->down_payment);
        $this->assertSame('250.50', $quote->shipping_cost);
        $this->assertSame('credit', $quote->payment_terms);
        $this->assertSame('2026-11-30', $quote->credit_due_date->toDateString());
    }

    public function test_it_validates_payment_fields(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);
        $items = [['product_variant_id' => $variant->id, 'boxes' => 1]];

        $this->postJson('/api/quotes', ['payment_terms' => 'credit', 'items' => $items])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['credit_due_date']);

        $this->postJson('/api/quotes', [
            'discount' => -1,
            'down_payment' => -1,
            'shipping_cost' => -1,
            'payment_terms' => 'layaway',
            'items' => $items,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['discount', 'down_payment', 'shipping_cost', 'payment_terms']);

        $quoteId = $this->postJson('/api/quotes', ['items' => $items])->assertCreated()->json('data.id');

        $this->putJson("/api/quotes/{$quoteId}", ['payment_terms' => 'credit', 'items' => $items])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['credit_due_date']);
    }

    public function test_switching_to_cash_clears_credit_due_date_even_if_sent(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);
        $items = [['product_variant_id' => $variant->id, 'boxes' => 1]];

        $quoteId = $this->postJson('/api/quotes', [
            'payment_terms' => 'credit',
            'credit_due_date' => '2026-11-30',
            'items' => $items,
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/quotes/{$quoteId}", [
            'payment_terms' => 'cash',
            'credit_due_date' => '2026-12-15',
            'items' => $items,
        ])
            ->assertOk()
            ->assertJsonPath('data.payment_terms', 'cash')
            ->assertJsonPath('data.credit_due_date', null);

        $this->assertNull(Quote::find($quoteId)->credit_due_date);
    }

    public function test_storing_a_cash_quote_discards_credit_due_date(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);
        $items = [['product_variant_id' => $variant->id, 'boxes' => 1]];

        // Explícito y por default (payment_terms no llega).
        foreach ([['payment_terms' => 'cash'], []] as $paymentTerms) {
            $quoteId = $this->postJson('/api/quotes', [
                ...$paymentTerms,
                'credit_due_date' => '2026-11-30',
                'items' => $items,
            ])
                ->assertCreated()
                ->assertJsonPath('data.payment_terms', 'cash')
                ->assertJsonPath('data.credit_due_date', null)
                ->json('data.id');

            $this->assertNull(Quote::find($quoteId)->credit_due_date);
        }
    }
}
