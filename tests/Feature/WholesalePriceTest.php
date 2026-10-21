<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\QuoteStatus;
use App\Models\SimpleProduct;
use App\Models\Supplier;
use App\Models\UnitType;
use App\Models\User;
use App\Services\DocumentPdfGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Precio de mayoreo opcional en productos simples: lo elige el vendedor por
 * línea (`price_type`), sin umbral de cantidad.
 */
class WholesalePriceTest extends TestCase
{
    use RefreshDatabase;

    private Category $simpleCategory;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('sanctum.stateful')[0] ?? 'http://localhost:5173');

        $this->actingAs(User::factory()->create());

        foreach (['Borrador', 'Convertida', 'Cancelada'] as $name) {
            QuoteStatus::create(['name' => $name]);
        }

        $this->simpleCategory = Category::create(['name' => 'Materiales', 'code_prefix' => 'MAT', 'product_form_type' => 'simple']);

        $product = Product::create([
            'supplier_id' => Supplier::create(['name' => 'Interceramic'])->id,
            'category_id' => Category::create(['name' => 'Pisos', 'code_prefix' => 'PIS'])->id,
            'unit_type_id' => UnitType::create(['name' => 'm2'])->id,
            'name' => 'Creato',
            'purchase_unit' => 'caja',
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $product->id,
            'code' => 'PIS-CREATO-TAU-60X120',
            'color' => 'Taupe',
            'size' => '60x120',
            'price_per_m2' => 100.00,
            'price_per_box' => 144.00,
            'm2_per_box' => 1.44,
            'stock_boxes' => 10,
        ]);
    }

    private function createSimpleProduct(?float $priceWholesale = 150.00): SimpleProduct
    {
        return SimpleProduct::create([
            'category_id' => $this->simpleCategory->id,
            'name' => 'Pegazulejo gris 20kg',
            'price' => 189.50,
            'price_wholesale' => $priceWholesale,
            'stock_quantity' => 20,
        ]);
    }

    public function test_it_creates_a_simple_product_with_wholesale_price(): void
    {
        $this->postJson('/api/simple-products', [
            'category_id' => $this->simpleCategory->id,
            'name' => 'Pegazulejo gris 20kg',
            'price' => 189.50,
            'price_wholesale' => 150,
        ])->assertCreated()
            ->assertJsonPath('data.price_wholesale', '150.00');

        $this->assertDatabaseHas('simple_products', ['name' => 'Pegazulejo gris 20kg', 'price_wholesale' => 150]);
    }

    public function test_it_creates_a_simple_product_without_wholesale_price(): void
    {
        $this->postJson('/api/simple-products', [
            'category_id' => $this->simpleCategory->id,
            'name' => 'Pegazulejo gris 20kg',
            'price' => 189.50,
        ])->assertCreated()
            ->assertJsonPath('data.price_wholesale', null);
    }

    public function test_it_rejects_a_negative_wholesale_price(): void
    {
        $this->postJson('/api/simple-products', [
            'category_id' => $this->simpleCategory->id,
            'name' => 'Pegazulejo gris 20kg',
            'price' => 189.50,
            'price_wholesale' => -1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('price_wholesale');
    }

    public function test_it_sets_and_clears_the_wholesale_price_on_update(): void
    {
        $simpleProduct = $this->createSimpleProduct(null);

        $this->putJson("/api/simple-products/{$simpleProduct->id}", ['price_wholesale' => 160])
            ->assertOk()
            ->assertJsonPath('data.price_wholesale', '160.00');

        $this->putJson("/api/simple-products/{$simpleProduct->id}", ['price_wholesale' => null])
            ->assertOk()
            ->assertJsonPath('data.price_wholesale', null);

        $this->assertNull($simpleProduct->fresh()->price_wholesale);
    }

    public function test_quote_line_uses_wholesale_price_when_requested(): void
    {
        $simpleProduct = $this->createSimpleProduct();

        $this->postJson('/api/quotes', [
            'items' => [
                ['simple_product_id' => $simpleProduct->id, 'quantity' => 2, 'price_type' => 'wholesale'],
                ['simple_product_id' => $simpleProduct->id, 'quantity' => 1],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.items.0.price_type', 'wholesale')
            ->assertJsonPath('data.items.0.unit_price', '150.00')
            ->assertJsonPath('data.items.0.line_total', '300.00')
            ->assertJsonPath('data.items.1.price_type', 'retail')
            ->assertJsonPath('data.items.1.unit_price', '189.50')
            ->assertJsonPath('data.total', '489.50');
    }

    public function test_it_rejects_a_wholesale_quote_line_for_a_product_without_wholesale_price(): void
    {
        $simpleProduct = $this->createSimpleProduct(null);

        $this->postJson('/api/quotes', [
            'items' => [
                ['simple_product_id' => $simpleProduct->id, 'quantity' => 2, 'price_type' => 'wholesale'],
            ],
        ])->assertUnprocessable()
            ->assertExactJson(['message' => 'El producto Pegazulejo gris 20kg no tiene precio de mayoreo configurado.']);

        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_it_rejects_updating_a_quote_to_wholesale_for_a_product_without_wholesale_price(): void
    {
        $simpleProduct = $this->createSimpleProduct(null);

        $quoteId = $this->postJson('/api/quotes', [
            'items' => [['simple_product_id' => $simpleProduct->id, 'quantity' => 2]],
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/quotes/{$quoteId}", [
            'items' => [['simple_product_id' => $simpleProduct->id, 'quantity' => 2, 'price_type' => 'wholesale']],
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'El producto Pegazulejo gris 20kg no tiene precio de mayoreo configurado.');

        // Las líneas existentes no se tocaron.
        $this->assertDatabaseHas('quote_items', ['quote_id' => $quoteId, 'price_type' => 'retail']);
    }

    public function test_it_rejects_an_unknown_price_type(): void
    {
        $simpleProduct = $this->createSimpleProduct();

        $this->postJson('/api/quotes', [
            'items' => [['simple_product_id' => $simpleProduct->id, 'quantity' => 2, 'price_type' => 'vip']],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.price_type');
    }

    public function test_it_rejects_price_type_on_variant_lines(): void
    {
        $this->postJson('/api/quotes', [
            'items' => [['product_variant_id' => $this->variant->id, 'boxes' => 2, 'price_type' => 'wholesale']],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.product_variant_id');

        $this->postJson('/api/sales', [
            'items' => [['product_variant_id' => $this->variant->id, 'boxes' => 2, 'unit_price' => 100, 'price_type' => 'retail']],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.product_variant_id');
    }

    public function test_conversion_keeps_price_type_and_resolves_the_current_price_of_that_type(): void
    {
        $simpleProduct = $this->createSimpleProduct();

        $quoteId = $this->postJson('/api/quotes', [
            'items' => [
                ['product_variant_id' => $this->variant->id, 'boxes' => 2],
                ['simple_product_id' => $simpleProduct->id, 'quantity' => 2, 'price_type' => 'wholesale'],
            ],
        ])->assertCreated()->json('data.id');

        // Cambió el mayoreo después de cotizar: la conversión usa el actual.
        $simpleProduct->update(['price_wholesale' => 140]);

        $this->getJson("/api/quotes/{$quoteId}/convert")
            ->assertOk()
            ->assertJsonPath('data.items.0.price_type', null)
            ->assertJsonPath('data.items.1.price_type', 'wholesale')
            ->assertJsonPath('data.items.1.unit_price', '140.00');
    }

    public function test_conversion_is_rejected_when_the_product_lost_its_wholesale_price(): void
    {
        $simpleProduct = $this->createSimpleProduct();

        $quoteId = $this->postJson('/api/quotes', [
            'items' => [['simple_product_id' => $simpleProduct->id, 'quantity' => 2, 'price_type' => 'wholesale']],
        ])->assertCreated()->json('data.id');

        $simpleProduct->update(['price_wholesale' => null]);

        $this->getJson("/api/quotes/{$quoteId}/convert")
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'No se puede convertir la cotización: los siguientes productos no tienen precio de mayoreo configurado: Pegazulejo gris 20kg.']);
    }

    public function test_converted_payload_can_be_posted_as_a_sale(): void
    {
        $simpleProduct = $this->createSimpleProduct();

        $quoteId = $this->postJson('/api/quotes', [
            'items' => [
                ['product_variant_id' => $this->variant->id, 'boxes' => 2],
                ['simple_product_id' => $simpleProduct->id, 'quantity' => 2, 'price_type' => 'wholesale'],
            ],
        ])->assertCreated()->json('data.id');

        $convertData = $this->getJson("/api/quotes/{$quoteId}/convert")->assertOk()->json('data');

        // Igual que el frontend: variantes mandan `boxes` (no m²) y sin price_type.
        $payload = [
            'quote_id' => $convertData['quote_id'],
            'items' => array_map(fn ($item) => $item['product_variant_id']
                ? ['product_variant_id' => $item['product_variant_id'], 'boxes' => $item['boxes'], 'unit_price' => $item['unit_price']]
                : ['simple_product_id' => $item['simple_product_id'], 'quantity' => $item['quantity'], 'unit_price' => $item['unit_price'], 'price_type' => $item['price_type']],
                $convertData['items']),
        ];

        $this->postJson('/api/sales', $payload)
            ->assertCreated()
            ->assertJsonPath('data.items.0.price_type', 'retail')
            ->assertJsonPath('data.items.1.price_type', 'wholesale')
            ->assertJsonPath('data.items.1.unit_price', '150.00');
    }

    public function test_sale_stores_price_type_as_sent_without_checking_it_against_unit_price(): void
    {
        // Sin price_wholesale y con un unit_price que no es ninguno de los dos:
        // en ventas el precio lo decide el vendedor.
        $simpleProduct = $this->createSimpleProduct(null);

        $this->postJson('/api/sales', [
            'items' => [
                ['simple_product_id' => $simpleProduct->id, 'quantity' => 2, 'unit_price' => 99.99, 'price_type' => 'wholesale'],
                ['simple_product_id' => $simpleProduct->id, 'quantity' => 1, 'unit_price' => 189.50],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.items.0.price_type', 'wholesale')
            ->assertJsonPath('data.items.0.unit_price', '99.99')
            ->assertJsonPath('data.items.1.price_type', 'retail');

        $this->assertDatabaseHas('sale_items', ['simple_product_id' => $simpleProduct->id, 'price_type' => 'wholesale', 'unit_price' => 99.99]);
    }

    public function test_pdf_lines_include_price_type(): void
    {
        // Solo la nota de venta: el Presupuesto usa la tabla del Comprobante,
        // que no distingue mayoreo.
        $simpleProduct = $this->createSimpleProduct();

        $saleId = $this->postJson('/api/sales', [
            'items' => [
                ['product_variant_id' => $this->variant->id, 'boxes' => 2, 'unit_price' => 100],
                ['simple_product_id' => $simpleProduct->id, 'quantity' => 1, 'unit_price' => 150, 'price_type' => 'wholesale'],
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

        $this->assertSame('retail', $captured['items'][0]['price_type']);
        $this->assertSame('wholesale', $captured['items'][1]['price_type']);

        // La plantilla real anota "Mayoreo" solo en la línea que lo trae.
        $html = view('pdf.document', ['document' => $captured])->render();

        $this->assertSame(1, substr_count($html, 'Mayoreo'));
    }
}
