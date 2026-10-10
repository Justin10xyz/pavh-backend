<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quote;
use App\Models\QuoteStatus;
use App\Models\SimpleProduct;
use App\Models\Supplier;
use App\Models\UnitType;
use App\Models\User;
use App\Services\DocumentPdfGenerator;
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

    public function test_it_shows_a_quote_whose_customer_was_soft_deleted(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);
        $customer = Customer::create(['name' => 'Juan Perez']);

        $created = $this->postJson('/api/quotes', [
            'customer_id' => $customer->id,
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
        ])->assertCreated();

        $customer->delete();

        $this->getJson("/api/quotes/{$created->json('data.id')}")
            ->assertOk()
            ->assertJsonPath('data.customer.name', 'Juan Perez');

        $this->getJson('/api/quotes?with=customer')
            ->assertOk()
            ->assertJsonPath('data.0.customer.name', 'Juan Perez');
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

    public function test_index_returns_quotes_newest_first(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);

        $ids = [];
        foreach (['2026-09-01 10:00:00', '2026-10-05 10:00:00', '2026-09-15 10:00:00'] as $createdAt) {
            $ids[] = $this->travelTo($createdAt, fn () => $this->postJson('/api/quotes', [
                'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
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
                'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
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
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
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

    public function test_it_creates_a_quote_with_a_simple_product_line(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);
        $simpleProduct = $this->createSimpleProduct();

        $response = $this->postJson('/api/quotes', [
            'items' => [
                ['product_variant_id' => $variant->id, 'quantity' => 2],
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
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 2]],
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
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 2]],
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
                ['product_variant_id' => $variant->id, 'quantity' => 2],
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
                ['product_variant_id' => $variant->id, 'quantity' => 2],
                ['simple_product_id' => $simpleProduct->id, 'quantity' => 3],
            ],
        ])->assertCreated()->json('data.id');

        // Soft-deleted después de cotizar: el documento se debe poder seguir reimprimiendo.
        $simpleProduct->delete();

        $response = $this->get("/api/quotes/{$quoteId}/pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertDownload('Cotizacion-COT-0001.pdf');

        $this->assertStringStartsWith('%PDF-', $response->streamedContent());
    }

    public function test_quote_pdf_shows_each_line_quantity_with_its_own_unit(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);
        $simpleProduct = $this->createSimpleProduct();

        $quoteId = $this->postJson('/api/quotes', [
            'items' => [
                ['product_variant_id' => $variant->id, 'quantity' => 2.5],
                ['simple_product_id' => $simpleProduct->id, 'quantity' => 3],
            ],
        ])->assertCreated()->json('data.id');

        // Captura la estructura neutral que arma el controlador (el PDF real
        // viene comprimido por dompdf y no hay parser de PDF en el proyecto).
        $captured = null;
        $this->mock(DocumentPdfGenerator::class, function ($mock) use (&$captured) {
            $mock->shouldReceive('generate')->once()->andReturnUsing(function (array $document) use (&$captured) {
                $captured = $document;

                return '%PDF-fake';
            });
        });

        $this->get("/api/quotes/{$quoteId}/pdf")->assertOk();

        $this->assertSame('m2', $captured['items'][0]['unit']);
        $this->assertSame('uds', $captured['items'][1]['unit']);

        // La plantilla real, con esa misma estructura.
        $html = view('pdf.document', ['document' => $captured])->render();

        $this->assertStringNotContainsString('Cant. (m²)', $html);
        $this->assertStringContainsString('>Cant.</th>', $html);
        $this->assertMatchesRegularExpression('/2\.50 m²/u', $html);
        $this->assertMatchesRegularExpression('/\b3 uds\./u', $html);
    }

    public function test_it_rejects_a_quote_with_fractional_quantity_on_a_simple_product_line(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);
        $simpleProduct = $this->createSimpleProduct();

        $this->postJson('/api/quotes', [
            'items' => [
                ['product_variant_id' => $variant->id, 'quantity' => 2.5],
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
}
