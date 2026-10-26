<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\QuoteStatus;
use App\Models\Sale;
use App\Models\SimpleProduct;
use App\Models\Supplier;
use App\Models\UnitType;
use App\Models\User;
use App\Services\DocumentPdfGenerator;
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

        foreach (['Borrador', 'Pagada', 'Convertida', 'Cancelada'] as $name) {
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
        // Venta por caja completa: 7 cajas × 1.44 = 10.08 m² × 100.00 = 1008.00,
        // y se descuentan exactamente las 7 cajas
        $variant = $this->createVariant('Taupe', 100.00, m2PerBox: 1.44, stockBoxes: 10);
        $customer = Customer::create(['name' => 'Juan Perez']);

        $response = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'items' => [
                ['product_variant_id' => $variant->id, 'boxes' => 7, 'unit_price' => 100.00],
            ],
        ])->assertCreated();

        $response->assertJsonPath('data.folio', 'V-0001')
            ->assertJsonPath('data.quote_id', null)
            ->assertJsonPath('data.items.0.boxes', 7)
            ->assertJsonPath('data.items.0.quantity', '10.080')
            ->assertJsonPath('data.items.0.unit_price', '100.00')
            ->assertJsonPath('data.items.0.line_total', '1008.00')
            ->assertJsonPath('data.subtotal', '1008.00')
            ->assertJsonPath('data.total', '1008.00');

        $this->assertDatabaseHas('product_variants', [
            'id' => $variant->id,
            'stock_boxes' => 3, // 10 - 7
        ]);
    }

    public function test_it_rejects_sale_with_insufficient_stock_and_rolls_back(): void
    {
        // stock=2 cajas, se piden 7 -> insuficiente
        $variant = $this->createVariant('Taupe', 100.00, m2PerBox: 1.44, stockBoxes: 2);

        $this->postJson('/api/sales', [
            'items' => [
                ['product_variant_id' => $variant->id, 'boxes' => 7, 'unit_price' => 100.00],
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
                ['product_variant_id' => $variant->id, 'boxes' => 7, 'unit_price' => 100.00],
            ],
        ])->assertStatus(422);

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseHas('product_variants', [
            'id' => $variant->id,
            'stock_boxes' => 10,
        ]);
    }

    public function test_it_generates_unique_sequential_sale_folios(): void
    {
        $variant = $this->createVariant('Taupe', 100.00, m2PerBox: 1.44, stockBoxes: 100);

        $first = $this->postJson('/api/sales', [
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 1, 'unit_price' => 100]],
        ])->assertCreated();

        $second = $this->postJson('/api/sales', [
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 1, 'unit_price' => 100]],
        ])->assertCreated();

        $first->assertJsonPath('data.folio', 'V-0001');
        $second->assertJsonPath('data.folio', 'V-0002');
    }

    public function test_it_aggregates_stock_check_across_duplicate_lines_of_the_same_variant(): void
    {
        // stock=5 cajas. Dos líneas de 3 cajas cada una;
        // una validación línea por línea contra el stock total (5) aprobaría ambas por separado,
        // pero agregadas son 6 cajas > 5 disponibles: debe rechazar. Prueba que la validación
        // de stock se agrega por variante antes de mutar nada.
        $variant = $this->createVariant('Taupe', 100.00, m2PerBox: 1.44, stockBoxes: 5);

        $this->postJson('/api/sales', [
            'items' => [
                ['product_variant_id' => $variant->id, 'boxes' => 3, 'unit_price' => 100],
                ['product_variant_id' => $variant->id, 'boxes' => 3, 'unit_price' => 100],
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
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 1, 'unit_price' => 100]],
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
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 1, 'unit_price' => 100]],
        ])->assertCreated();

        $customer->delete();

        $this->getJson("/api/sales/{$created->json('data.id')}")
            ->assertOk()
            ->assertJsonPath('data.customer.name', 'Juan Perez');

        $this->getJson('/api/sales?with=customer')
            ->assertOk()
            ->assertJsonPath('data.0.customer.name', 'Juan Perez');
    }

    public function test_it_rejects_creating_a_sale_for_a_soft_deleted_customer(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);
        $customer = Customer::create(['name' => 'Juan Perez']);
        $customer->delete();

        $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 1, 'unit_price' => 100]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('customer_id');

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'stock_boxes' => 10]);
    }

    public function test_it_rejects_creating_a_sale_with_soft_deleted_variant_and_simple_product(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);
        $simpleProduct = $this->createSimpleProduct();
        $variant->delete();
        $simpleProduct->delete();

        $this->postJson('/api/sales', [
            'items' => [
                ['product_variant_id' => $variant->id, 'boxes' => 1, 'unit_price' => 100],
                ['simple_product_id' => $simpleProduct->id, 'quantity' => 2, 'unit_price' => 189.50],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.product_variant_id', 'items.1.simple_product_id']);

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'stock_boxes' => 10]);
        $this->assertDatabaseHas('simple_products', ['id' => $simpleProduct->id, 'stock_quantity' => 20]);
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
                    ['product_variant_id' => $variant->id, 'boxes' => 1, 'unit_price' => 100.00],
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

    public function test_index_always_includes_customer_without_with_param(): void
    {
        $juan = Customer::create(['name' => 'Juan Perez']);
        $maria = Customer::create(['name' => 'Maria Lopez']);
        $this->createSaleAt('V-0001', '2026-10-01 10:00:00', $juan);
        $this->createSaleAt('V-0002', '2026-10-02 10:00:00', $maria);
        $maria->delete();

        DB::enableQueryLog();

        $this->getJson('/api/sales')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.customer.id', $maria->id)
            ->assertJsonPath('data.0.customer.name', 'Maria Lopez')
            ->assertJsonPath('data.1.customer.id', $juan->id)
            ->assertJsonPath('data.1.customer.name', 'Juan Perez');

        // El listado carga customer siempre: una sola consulta, no una por venta.
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
                ['product_variant_id' => $variant->id, 'boxes' => 2, 'unit_price' => 100.00],
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
                ['product_variant_id' => $variant->id, 'boxes' => 1, 'unit_price' => 100.00],
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
                ['product_variant_id' => $variant->id, 'boxes' => 7, 'unit_price' => 100.00],
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

    public function test_it_derives_variant_square_meters_from_whole_boxes(): void
    {
        // m2_per_box con 3 decimales: 3 cajas × 1.125 = 3.375 m² (no se redondea a 2)
        $variant = $this->createVariant('Taupe', 100.00, m2PerBox: 1.125, stockBoxes: 10);

        $this->postJson('/api/sales', [
            'items' => [
                ['product_variant_id' => $variant->id, 'boxes' => 3, 'unit_price' => 100.00],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.items.0.boxes', 3)
            ->assertJsonPath('data.items.0.quantity', '3.375')
            ->assertJsonPath('data.items.0.line_total', '337.50')
            ->assertJsonPath('data.total', '337.50');

        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'stock_boxes' => 7]);
    }

    public function test_it_requires_whole_boxes_for_a_variant_line(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);

        // Sin boxes (ej. un cliente que todavía manda m²)
        $this->postJson('/api/sales', [
            'items' => [['product_variant_id' => $variant->id, 'unit_price' => 100.00]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.boxes']);

        // Fracción de caja
        $this->postJson('/api/sales', [
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 2.5, 'unit_price' => 100.00]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.boxes']);

        // Cero cajas
        $this->postJson('/api/sales', [
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 0, 'unit_price' => 100.00]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.boxes']);

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_it_rejects_client_square_meters_on_a_variant_line(): void
    {
        // Los m² de una variante los deriva el backend; aceptarlos permitiría
        // cobrar m² distintos a los que cubren las cajas entregadas.
        $variant = $this->createVariant('Taupe', 100.00);

        $this->postJson('/api/sales', [
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 2, 'quantity' => 1, 'unit_price' => 100.00]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.boxes']);

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_it_rejects_boxes_on_a_simple_product_line(): void
    {
        $simpleProduct = $this->createSimpleProduct();

        $this->postJson('/api/sales', [
            'items' => [['simple_product_id' => $simpleProduct->id, 'boxes' => 2, 'quantity' => 2, 'unit_price' => 189.50]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.simple_product_id']);

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

        $this->postJson('/api/sales', [
            'items' => [
                ['product_variant_id' => $variant->id, 'boxes' => 7, 'unit_price' => 100.00],
                ['simple_product_id' => $simpleProduct->id, 'quantity' => 5, 'unit_price' => 189.50],
            ],
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Stock insuficiente para Pegazulejo gris 20kg. Disponible: 2 unidades, se requieren 5.');

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_items', 0);
        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'stock_boxes' => 10]);
        $this->assertDatabaseHas('simple_products', ['id' => $simpleProduct->id, 'stock_quantity' => 2]);
    }

    public function test_it_validates_and_decrements_stock_for_mixed_variant_and_simple_product_lines(): void
    {
        // 7 cajas × 1.44 = 10.08 m² × 100.00 = 1008.00 ; 4 × 189.50 = 758.00
        $variant = $this->createVariant('Taupe', 100.00, m2PerBox: 1.44, stockBoxes: 10);
        $simpleProduct = $this->createSimpleProduct(price: 189.50, stockQuantity: 20);

        $this->postJson('/api/sales', [
            'items' => [
                ['product_variant_id' => $variant->id, 'boxes' => 7, 'unit_price' => 100.00],
                ['simple_product_id' => $simpleProduct->id, 'quantity' => 4, 'unit_price' => 189.50],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.subtotal', '1766.00');

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

    public function test_it_downloads_a_sale_with_a_simple_product_line_as_pdf(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);
        $simpleProduct = $this->createSimpleProduct();

        $saleId = $this->postJson('/api/sales', [
            'items' => [
                ['product_variant_id' => $variant->id, 'boxes' => 2, 'unit_price' => 100.00],
                ['simple_product_id' => $simpleProduct->id, 'quantity' => 3, 'unit_price' => 189.50],
            ],
        ])->assertCreated()->json('data.id');

        // Soft-deleted después de la venta: el documento se debe poder seguir reimprimiendo.
        $simpleProduct->delete();

        $response = $this->get("/api/sales/{$saleId}/pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertDownload('Venta-V-0001.pdf');

        $this->assertStringStartsWith('%PDF-', $response->streamedContent());
    }

    public function test_sale_pdf_passes_each_line_unit_to_the_template(): void
    {
        $variant = $this->createVariant('Taupe', 100.00);
        $simpleProduct = $this->createSimpleProduct();

        $saleId = $this->postJson('/api/sales', [
            'items' => [
                ['product_variant_id' => $variant->id, 'boxes' => 2, 'unit_price' => 100.00],
                ['simple_product_id' => $simpleProduct->id, 'quantity' => 3, 'unit_price' => 189.50],
            ],
        ])->assertCreated()->json('data.id');

        $captured = null;
        $this->mock(DocumentPdfGenerator::class, function ($mock) use (&$captured) {
            $mock->shouldReceive('generate')->once()->andReturnUsing(function (array $document) use (&$captured) {
                $captured = $document;

                return '%PDF-fake';
            });
        });

        $this->get("/api/sales/{$saleId}/pdf")->assertOk();

        $this->assertSame('m2', $captured['items'][0]['unit']);
        $this->assertSame(2, $captured['items'][0]['boxes']);
        $this->assertEqualsWithDelta(2.88, $captured['items'][0]['quantity'], 0.0001);
        $this->assertSame('uds', $captured['items'][1]['unit']);
        $this->assertNull($captured['items'][1]['boxes']);
        $this->assertSame('Pegazulejo gris 20kg', $captured['items'][1]['variant_label']);

        // Variante: cajas como cantidad, m² que cubren debajo y precio por m²
        // con el precio por caja debajo (1.44 m²/caja × $100).
        $html = view('pdf.document', ['document' => $captured])->render();

        $this->assertMatchesRegularExpression('/\b2 cajas/u', $html);
        $this->assertMatchesRegularExpression('/2\.88 m²/u', $html);
        $this->assertMatchesRegularExpression('/\$100\.00<span class="muted">\/m²<\/span>/u', $html);
        $this->assertMatchesRegularExpression('/\$144\.00\/caja/u', $html);
        $this->assertSame(1, substr_count($html, '/caja'));
        $this->assertMatchesRegularExpression('/\b3 uds\./u', $html);
    }

    public function test_pdf_template_shows_only_square_meters_for_a_legacy_variant_line_without_boxes(): void
    {
        // Líneas registradas antes del cambio a cajas cuya variante no tenía
        // m2_per_box: quedaron con boxes = null y se muestran tal cual se emitieron.
        $html = view('pdf.document', ['document' => [
            'document_type' => 'Venta',
            'folio' => 'V-0001',
            'date' => '01/10/2026',
            'customer' => null,
            'items' => [[
                'variant_label' => 'Creato Taupe 60x120',
                'unit' => 'm2',
                'boxes' => null,
                'quantity' => 5.0,
                'unit_price' => 100.0,
                'line_total' => 500.0,
            ]],
            'subtotal' => 500.0,
            'total' => 500.0,
        ]])->render();

        $this->assertMatchesRegularExpression('/\b5 m²/u', $html);
        $this->assertStringNotContainsString('caja', $html);
    }
}
