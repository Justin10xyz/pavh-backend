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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SaleApiTest extends TestCase
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

    private function createVariant(string $color, float $pricePerM2, ?float $m2PerBox = 1.44, int $stockBoxes = 10): ProductVariant
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
            'm2_per_box' => $m2PerBox,
            'stock_boxes' => $stockBoxes,
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
    private function createSaleAt(string $folio, string $createdAt, ?Customer $customer = null): Sale
    {
        return $this->travelTo($createdAt, fn () => Sale::create([
            'folio' => $folio,
            'customer_id' => $customer?->id,
            'subtotal' => 100,
            'total' => 100,
        ]));
    }

    public function test_it_creates_a_direct_sale_and_decrements_stock(): void
    {
        // m2_per_box=1.44, quantity=10 m2 -> ceil(10/1.44)=7 cajas descontadas
        $variant = $this->createVariant('Taupe', 100.00, m2PerBox: 1.44, stockBoxes: 10);
        $customer = Customer::create(['name' => 'Juan Perez']);

        $response = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'items' => [
                ['product_variant_id' => $variant->id, 'quantity' => 10, 'unit_price' => 100.00],
            ],
        ])->assertCreated();

        $response->assertJsonPath('data.folio', 'V-0001')
            ->assertJsonPath('data.quote_id', null)
            ->assertJsonPath('data.items.0.quantity', '10.00')
            ->assertJsonPath('data.items.0.unit_price', '100.00')
            ->assertJsonPath('data.items.0.line_total', '1000.00')
            ->assertJsonPath('data.subtotal', '1000.00')
            ->assertJsonPath('data.total', '1000.00');

        $this->assertDatabaseHas('product_variants', [
            'id' => $variant->id,
            'stock_boxes' => 3, // 10 - 7
        ]);
    }

    public function test_it_rejects_sale_with_insufficient_stock_and_rolls_back(): void
    {
        // stock=2 cajas, se necesitan ceil(10/1.44)=7 -> insuficiente
        $variant = $this->createVariant('Taupe', 100.00, m2PerBox: 1.44, stockBoxes: 2);

        $this->postJson('/api/sales', [
            'items' => [
                ['product_variant_id' => $variant->id, 'quantity' => 10, 'unit_price' => 100.00],
            ],
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Stock insuficiente para PIS-CREATO-Taupe-60X120. Disponible: 2 cajas, se requieren 7.');

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_items', 0);
        $this->assertDatabaseHas('product_variants', [
            'id' => $variant->id,
            'stock_boxes' => 2,
        ]);
    }

    public function test_it_rejects_sale_when_variant_has_no_m2_per_box_conversion(): void
    {
        $variant = $this->createVariant('Taupe', 100.00, m2PerBox: null, stockBoxes: 10);

        $this->postJson('/api/sales', [
            'items' => [
                ['product_variant_id' => $variant->id, 'quantity' => 10, 'unit_price' => 100.00],
            ],
        ])->assertStatus(422);

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseHas('product_variants', [
            'id' => $variant->id,
            'stock_boxes' => 10,
        ]);
    }

    public function test_full_quote_to_sale_conversion_flow(): void
    {
        $variant = $this->createVariant('Taupe', 100.00, m2PerBox: 1.44, stockBoxes: 10);
        $customer = Customer::create(['name' => 'Juan Perez']);

        $quoteResponse = $this->postJson('/api/quotes', [
            'customer_id' => $customer->id,
            'items' => [
                ['product_variant_id' => $variant->id, 'quantity' => 5],
            ],
        ])->assertCreated();

        $quoteId = $quoteResponse->json('data.id');

        // El precio de catálogo cambia después de cotizar; convert debe reflejar el precio de HOY, no el congelado en la quote.
        $variant->update(['price_per_m2' => 120.00]);

        $convertResponse = $this->getJson("/api/quotes/{$quoteId}/convert")->assertOk();

        $convertResponse->assertJsonPath('data.quote_id', $quoteId)
            ->assertJsonPath('data.customer_id', $customer->id)
            ->assertJsonPath('data.items.0.product_variant_id', $variant->id)
            ->assertJsonPath('data.items.0.quantity', '5.00')
            ->assertJsonPath('data.items.0.unit_price', '120.00');

        $saleResponse = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'quote_id' => $quoteId,
            'items' => [
                ['product_variant_id' => $variant->id, 'quantity' => 5, 'unit_price' => 120.00],
            ],
        ])->assertCreated();

        $saleResponse->assertJsonPath('data.folio', 'V-0001')
            ->assertJsonPath('data.quote_id', $quoteId)
            ->assertJsonPath('data.subtotal', '600.00');

        $this->assertDatabaseHas('quotes', [
            'id' => $quoteId,
            'quote_status_id' => QuoteStatus::where('name', 'Convertida')->first()->id,
        ]);
    }

    public function test_it_rejects_converting_an_already_converted_quote(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);

        $quoteResponse = $this->postJson('/api/quotes', [
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
        ])->assertCreated();

        $quoteId = $quoteResponse->json('data.id');

        Quote::find($quoteId)->update([
            'quote_status_id' => QuoteStatus::where('name', 'Convertida')->first()->id,
        ]);

        $this->getJson("/api/quotes/{$quoteId}/convert")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Esta cotización ya fue convertida a venta.');
    }

    public function test_it_rejects_converting_a_cancelled_quote(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);

        $quoteResponse = $this->postJson('/api/quotes', [
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
        ])->assertCreated();

        $quoteId = $quoteResponse->json('data.id');

        Quote::find($quoteId)->update([
            'quote_status_id' => QuoteStatus::where('name', 'Cancelada')->first()->id,
        ]);

        $this->getJson("/api/quotes/{$quoteId}/convert")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Esta cotización está cancelada y no se puede convertir a venta.');
    }

    public function test_it_rejects_converting_a_quote_with_soft_deleted_variants(): void
    {
        $available = $this->createVariant('Taupe', 100.00);
        $deleted = $this->createVariant('Gris', 100.00);

        $quoteResponse = $this->postJson('/api/quotes', [
            'items' => [
                ['product_variant_id' => $available->id, 'quantity' => 1],
                ['product_variant_id' => $deleted->id, 'quantity' => 2],
            ],
        ])->assertCreated();

        $quoteId = $quoteResponse->json('data.id');

        $deleted->delete();

        $this->getJson("/api/quotes/{$quoteId}/convert")
            ->assertStatus(422)
            ->assertJsonPath('message', 'No se puede convertir la cotización: las siguientes variantes ya no están disponibles: PIS-CREATO-Gris-60X120.');
    }

    public function test_it_rejects_creating_a_sale_from_an_already_converted_quote(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);

        $quoteResponse = $this->postJson('/api/quotes', [
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
        ])->assertCreated();

        $quoteId = $quoteResponse->json('data.id');

        Quote::find($quoteId)->update([
            'quote_status_id' => QuoteStatus::where('name', 'Convertida')->first()->id,
        ]);

        $this->postJson('/api/sales', [
            'quote_id' => $quoteId,
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1, 'unit_price' => 100]],
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Esta cotización ya fue convertida a venta.');

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_it_rejects_creating_a_sale_from_a_cancelled_quote(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);

        $quoteResponse = $this->postJson('/api/quotes', [
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
        ])->assertCreated();

        $quoteId = $quoteResponse->json('data.id');

        Quote::find($quoteId)->update([
            'quote_status_id' => QuoteStatus::where('name', 'Cancelada')->first()->id,
        ]);

        $this->postJson('/api/sales', [
            'quote_id' => $quoteId,
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1, 'unit_price' => 100]],
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Esta cotización está cancelada y no se puede convertir a venta.');

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'stock_boxes' => 10]);
    }

    public function test_it_generates_unique_sequential_sale_folios(): void
    {
        $variant = $this->createVariant('Taupe', 100.00, m2PerBox: 1.44, stockBoxes: 100);

        $first = $this->postJson('/api/sales', [
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1, 'unit_price' => 100]],
        ])->assertCreated();

        $second = $this->postJson('/api/sales', [
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1, 'unit_price' => 100]],
        ])->assertCreated();

        $first->assertJsonPath('data.folio', 'V-0001');
        $second->assertJsonPath('data.folio', 'V-0002');
    }

    public function test_it_aggregates_stock_check_across_duplicate_lines_of_the_same_variant(): void
    {
        // stock=5 cajas. Dos líneas de 4 m2 cada una -> ceil(4/1.44)=3 cajas cada línea;
        // una validación línea por línea contra el stock total (5) aprobaría ambas por separado,
        // pero agregadas son 6 cajas > 5 disponibles: debe rechazar. Prueba que la validación
        // de stock se agrega por variante antes de mutar nada.
        $variant = $this->createVariant('Taupe', 100.00, m2PerBox: 1.44, stockBoxes: 5);

        $this->postJson('/api/sales', [
            'items' => [
                ['product_variant_id' => $variant->id, 'quantity' => 4, 'unit_price' => 100],
                ['product_variant_id' => $variant->id, 'quantity' => 4, 'unit_price' => 100],
            ],
        ])->assertStatus(422);

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'stock_boxes' => 5]);
    }

    public function test_it_shows_a_sale_with_items_and_customer(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);
        $customer = Customer::create(['name' => 'Juan Perez']);

        $created = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1, 'unit_price' => 100]],
        ])->assertCreated();

        $this->getJson("/api/sales/{$created->json('data.id')}")
            ->assertOk()
            ->assertJsonPath('data.customer.name', 'Juan Perez')
            ->assertJsonCount(1, 'data.items');
    }

    public function test_it_shows_a_sale_whose_customer_was_soft_deleted(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);
        $customer = Customer::create(['name' => 'Juan Perez']);

        $created = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1, 'unit_price' => 100]],
        ])->assertCreated();

        $customer->delete();

        $this->getJson("/api/sales/{$created->json('data.id')}")
            ->assertOk()
            ->assertJsonPath('data.customer.name', 'Juan Perez');

        $this->getJson('/api/sales?with=customer')
            ->assertOk()
            ->assertJsonPath('data.0.customer.name', 'Juan Perez');
    }

    public function test_index_returns_all_sales_newest_first_without_date_filter(): void
    {
        $this->createSaleAt('V-0001', '2026-09-01 10:00:00');
        $this->createSaleAt('V-0002', '2026-10-05 10:00:00');
        $this->createSaleAt('V-0003', '2026-09-15 10:00:00');

        $this->getJson('/api/sales')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.folio', 'V-0002')
            ->assertJsonPath('data.1.folio', 'V-0003')
            ->assertJsonPath('data.2.folio', 'V-0001');
    }

    public function test_index_filters_by_from_date_only(): void
    {
        $this->createSaleAt('V-0001', '2026-09-30 23:59:59');
        $this->createSaleAt('V-0002', '2026-10-01 00:00:00');
        $this->createSaleAt('V-0003', '2026-10-05 10:00:00');

        $this->getJson('/api/sales?from=2026-10-01')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.folio', 'V-0003')
            ->assertJsonPath('data.1.folio', 'V-0002');
    }

    public function test_index_filters_by_from_and_to_dates_inclusive(): void
    {
        $this->createSaleAt('V-0001', '2026-09-30 23:59:59');
        $this->createSaleAt('V-0002', '2026-10-01 08:00:00');
        $this->createSaleAt('V-0003', '2026-10-03 23:59:59');
        $this->createSaleAt('V-0004', '2026-10-04 00:00:00');

        $this->getJson('/api/sales?from=2026-10-01&to=2026-10-03')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.folio', 'V-0003')
            ->assertJsonPath('data.1.folio', 'V-0002');
    }

    public function test_index_rejects_invalid_date_format(): void
    {
        $this->getJson('/api/sales?from=01/10/2026')
            ->assertStatus(422)
            ->assertJsonValidationErrors('from');
    }

    public function test_index_filters_by_customer(): void
    {
        $juan = Customer::create(['name' => 'Juan Perez']);
        $maria = Customer::create(['name' => 'Maria Lopez']);

        $this->createSaleAt('V-0001', '2026-10-01 10:00:00', $juan);
        $this->createSaleAt('V-0002', '2026-10-02 10:00:00', $maria);
        $this->createSaleAt('V-0003', '2026-10-03 10:00:00', $juan);
        $this->createSaleAt('V-0004', '2026-10-04 10:00:00');

        $this->getJson("/api/sales?customer_id={$juan->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.folio', 'V-0003')
            ->assertJsonPath('data.1.folio', 'V-0001');
    }

    public function test_index_combines_customer_and_date_filters(): void
    {
        $juan = Customer::create(['name' => 'Juan Perez']);
        $maria = Customer::create(['name' => 'Maria Lopez']);

        $this->createSaleAt('V-0001', '2026-09-30 10:00:00', $juan);
        $this->createSaleAt('V-0002', '2026-10-02 10:00:00', $juan);
        $this->createSaleAt('V-0003', '2026-10-02 10:00:00', $maria);

        $this->getJson("/api/sales?customer_id={$juan->id}&from=2026-10-01")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.folio', 'V-0002');
    }

    public function test_index_filters_by_soft_deleted_customer(): void
    {
        $juan = Customer::create(['name' => 'Juan Perez']);
        $this->createSaleAt('V-0001', '2026-10-01 10:00:00', $juan);
        $juan->delete();

        $this->getJson("/api/sales?customer_id={$juan->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.folio', 'V-0001');
    }

    public function test_index_rejects_non_integer_customer_id(): void
    {
        $this->getJson('/api/sales?customer_id=abc')
            ->assertStatus(422)
            ->assertJsonValidationErrors('customer_id');
    }

    public function test_index_with_customer_and_items_eager_loads_both_relations(): void
    {
        $variant = $this->createVariant('Taupe', 100.00, stockBoxes: 20);

        foreach (['Juan Perez', 'Maria Lopez'] as $name) {
            $this->postJson('/api/sales', [
                'customer_id' => Customer::create(['name' => $name])->id,
                'items' => [
                    ['product_variant_id' => $variant->id, 'quantity' => 1, 'unit_price' => 100.00],
                ],
            ])->assertCreated();
        }

        DB::enableQueryLog();

        $response = $this->getJson('/api/sales?with=customer,items,notARealRelation')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonCount(1, 'data.0.items')
            ->assertJsonCount(1, 'data.1.items')
            ->assertJsonPath('data.0.items.0.product_variant_id', $variant->id);

        $this->assertEqualsCanonicalizing(
            ['Juan Perez', 'Maria Lopez'],
            array_column($response->json('data.*.customer'), 'name'),
        );

        // Con eager loading, una sola consulta a customers para ambas ventas
        // (lazy loading haría una por venta).
        $customerQueries = collect(DB::getQueryLog())
            ->filter(fn ($entry) => str_contains($entry['query'], 'from "customers"'));

        $this->assertCount(1, $customerQueries);
    }

    public function test_it_downloads_a_sale_as_pdf(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);
        $customer = Customer::create(['name' => 'José Núñez']);

        $saleId = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'items' => [
                ['product_variant_id' => $variant->id, 'quantity' => 2.5, 'unit_price' => 100.00],
            ],
        ])->assertCreated()->json('data.id');

        $response = $this->get("/api/sales/{$saleId}/pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertDownload('Venta-V-0001.pdf');

        $this->assertStringStartsWith('%PDF-', $response->streamedContent());
    }

    public function test_it_shows_a_sale_whose_variant_and_product_were_soft_deleted(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);

        $saleId = $this->postJson('/api/sales', [
            'items' => [
                ['product_variant_id' => $variant->id, 'quantity' => 1, 'unit_price' => 100.00],
            ],
        ])->assertCreated()->json('data.id');

        $variant->delete();
        $variant->product->delete();

        $this->getJson("/api/sales/{$saleId}")
            ->assertOk()
            ->assertJsonPath('data.items.0.product_variant.id', $variant->id)
            ->assertJsonPath('data.items.0.product_variant.color', 'Taupe')
            ->assertJsonPath('data.items.0.product_variant.product.name', 'Creato');
    }

    public function test_it_creates_a_sale_with_a_simple_product_line(): void
    {
        $variant = $this->createVariant('Taupe', 100.00, m2PerBox: 1.44, stockBoxes: 10);
        $simpleProduct = $this->createSimpleProduct();

        $response = $this->postJson('/api/sales', [
            'items' => [
                ['product_variant_id' => $variant->id, 'quantity' => 10, 'unit_price' => 100.00],
                ['simple_product_id' => $simpleProduct->id, 'quantity' => 3, 'unit_price' => 189.50],
            ],
        ])->assertCreated();

        $response->assertJsonPath('data.items.1.product_variant_id', null)
            ->assertJsonPath('data.items.1.product_variant', null)
            ->assertJsonPath('data.items.1.simple_product_id', $simpleProduct->id)
            ->assertJsonPath('data.items.1.simple_product.name', 'Pegazulejo gris 20kg')
            ->assertJsonPath('data.items.1.simple_product.price', '189.50');

        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $response->json('data.id'),
            'product_variant_id' => null,
            'simple_product_id' => $simpleProduct->id,
        ]);

        // La línea de variante sigue descontando stock igual que antes.
        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'stock_boxes' => 3]);

        $this->getJson("/api/sales/{$response->json('data.id')}")
            ->assertOk()
            ->assertJsonPath('data.items.1.simple_product.name', 'Pegazulejo gris 20kg');
    }

    public function test_it_rejects_a_sale_line_with_both_variant_and_simple_product(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);
        $simpleProduct = $this->createSimpleProduct();

        $this->postJson('/api/sales', [
            'items' => [
                ['product_variant_id' => $variant->id, 'simple_product_id' => $simpleProduct->id, 'quantity' => 1, 'unit_price' => 100.00],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.product_variant_id']);

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_it_rejects_a_sale_line_without_variant_or_simple_product(): void
    {
        $this->postJson('/api/sales', [
            'items' => [['quantity' => 1, 'unit_price' => 100.00]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.product_variant_id', 'items.0.simple_product_id']);

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_convert_resolves_current_price_for_simple_product_lines(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);
        $simpleProduct = $this->createSimpleProduct(price: 189.50);

        $quoteId = $this->postJson('/api/quotes', [
            'items' => [
                ['product_variant_id' => $variant->id, 'quantity' => 5],
                ['simple_product_id' => $simpleProduct->id, 'quantity' => 3],
            ],
        ])->assertCreated()->json('data.id');

        // Igual que con variantes: convert refleja el precio de HOY, no el congelado en la cotización.
        $simpleProduct->update(['price' => 210.00]);

        $this->getJson("/api/quotes/{$quoteId}/convert")
            ->assertOk()
            ->assertJsonPath('data.items.0.product_variant_id', $variant->id)
            ->assertJsonPath('data.items.0.simple_product_id', null)
            ->assertJsonPath('data.items.0.unit_price', '100.00')
            ->assertJsonPath('data.items.1.product_variant_id', null)
            ->assertJsonPath('data.items.1.simple_product_id', $simpleProduct->id)
            ->assertJsonPath('data.items.1.quantity', '3.00')
            ->assertJsonPath('data.items.1.unit_price', '210.00');
    }

    public function test_it_rejects_converting_a_quote_with_soft_deleted_simple_products(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);
        $simpleProduct = $this->createSimpleProduct();

        $quoteId = $this->postJson('/api/quotes', [
            'items' => [
                ['product_variant_id' => $variant->id, 'quantity' => 1],
                ['simple_product_id' => $simpleProduct->id, 'quantity' => 2],
            ],
        ])->assertCreated()->json('data.id');

        $simpleProduct->delete();

        $this->getJson("/api/quotes/{$quoteId}/convert")
            ->assertStatus(422)
            ->assertJsonPath('message', 'No se puede convertir la cotización: los siguientes productos ya no están disponibles: Pegazulejo gris 20kg.');
    }

    public function test_it_names_both_soft_deleted_variants_and_simple_products_when_converting(): void
    {
        $variant = $this->createVariant('Gris', 100.00);
        $simpleProduct = $this->createSimpleProduct();

        $quoteId = $this->postJson('/api/quotes', [
            'items' => [
                ['product_variant_id' => $variant->id, 'quantity' => 1],
                ['simple_product_id' => $simpleProduct->id, 'quantity' => 2],
            ],
        ])->assertCreated()->json('data.id');

        $variant->delete();
        $simpleProduct->delete();

        $this->getJson("/api/quotes/{$quoteId}/convert")
            ->assertStatus(422)
            ->assertJsonPath('message', 'No se puede convertir la cotización: las siguientes variantes ya no están disponibles: PIS-CREATO-Gris-60X120; los siguientes productos ya no están disponibles: Pegazulejo gris 20kg.');
    }

    public function test_it_decrements_stock_for_a_simple_product_line(): void
    {
        $simpleProduct = $this->createSimpleProduct(price: 189.50, stockQuantity: 20);

        $this->postJson('/api/sales', [
            'items' => [
                ['simple_product_id' => $simpleProduct->id, 'quantity' => 3, 'unit_price' => 189.50],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.subtotal', '568.50');

        // Sin conversión de unidad: 20 - 3, no ceil() ni factor.
        $this->assertDatabaseHas('simple_products', ['id' => $simpleProduct->id, 'stock_quantity' => 17]);
    }

    public function test_it_aggregates_stock_check_across_duplicate_lines_of_the_same_simple_product(): void
    {
        $simpleProduct = $this->createSimpleProduct(stockQuantity: 5);

        // 3 + 3 = 6 > 5, aunque cada línea por separado sí alcanzaría.
        $this->postJson('/api/sales', [
            'items' => [
                ['simple_product_id' => $simpleProduct->id, 'quantity' => 3, 'unit_price' => 189.50],
                ['simple_product_id' => $simpleProduct->id, 'quantity' => 3, 'unit_price' => 189.50],
            ],
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Stock insuficiente para Pegazulejo gris 20kg. Disponible: 5 unidades, se requieren 6.');

        $this->assertDatabaseHas('simple_products', ['id' => $simpleProduct->id, 'stock_quantity' => 5]);
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_it_rejects_sale_with_insufficient_simple_product_stock_without_mutating_anything(): void
    {
        $variant = $this->createVariant('Taupe', 100.00, m2PerBox: 1.44, stockBoxes: 10);
        $simpleProduct = $this->createSimpleProduct(stockQuantity: 2);

        $quoteId = $this->postJson('/api/quotes', [
            'items' => [['simple_product_id' => $simpleProduct->id, 'quantity' => 5]],
        ])->json('data.id');

        $this->postJson('/api/sales', [
            'quote_id' => $quoteId,
            'items' => [
                ['product_variant_id' => $variant->id, 'quantity' => 10, 'unit_price' => 100.00],
                ['simple_product_id' => $simpleProduct->id, 'quantity' => 5, 'unit_price' => 189.50],
            ],
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Stock insuficiente para Pegazulejo gris 20kg. Disponible: 2 unidades, se requieren 5.');

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_items', 0);
        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'stock_boxes' => 10]);
        $this->assertDatabaseHas('simple_products', ['id' => $simpleProduct->id, 'stock_quantity' => 2]);
        $this->assertDatabaseHas('quotes', [
            'id' => $quoteId,
            'quote_status_id' => QuoteStatus::where('name', 'Borrador')->first()->id,
        ]);
    }

    public function test_it_validates_and_decrements_stock_for_mixed_variant_and_simple_product_lines(): void
    {
        // m2_per_box=1.44, quantity=10 m2 -> ceil(10/1.44)=7 cajas
        $variant = $this->createVariant('Taupe', 100.00, m2PerBox: 1.44, stockBoxes: 10);
        $simpleProduct = $this->createSimpleProduct(price: 189.50, stockQuantity: 20);

        $this->postJson('/api/sales', [
            'items' => [
                ['product_variant_id' => $variant->id, 'quantity' => 10, 'unit_price' => 100.00],
                ['simple_product_id' => $simpleProduct->id, 'quantity' => 4, 'unit_price' => 189.50],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.subtotal', '1758.00');

        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'stock_boxes' => 3]);
        $this->assertDatabaseHas('simple_products', ['id' => $simpleProduct->id, 'stock_quantity' => 16]);
    }

    public function test_it_rejects_fractional_quantity_for_a_simple_product_line(): void
    {
        $simpleProduct = $this->createSimpleProduct(stockQuantity: 20);

        $this->postJson('/api/sales', [
            'items' => [
                ['simple_product_id' => $simpleProduct->id, 'quantity' => 2.5, 'unit_price' => 189.50],
            ],
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'La cantidad de Pegazulejo gris 20kg debe ser un número entero de unidades.');

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseHas('simple_products', ['id' => $simpleProduct->id, 'stock_quantity' => 20]);
    }
}
