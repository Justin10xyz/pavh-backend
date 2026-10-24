<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\QuoteStatus;
use App\Models\Supplier;
use App\Models\UnitType;
use App\Models\User;
use App\Services\DocumentPdfGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pisos con unit_type "pieza" (mosaicos/decorados): la línea trae `boxes`
 * como piezas, quantity = boxes (sin m²) y el precio es price_per_box.
 */
class PieceSoldVariantTest extends TestCase
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

    private function createPieceVariant(float $pricePerPiece = 85.50, ?int $stock = 20): ProductVariant
    {
        $product = Product::create([
            'supplier_id' => Supplier::create(['name' => 'Interceramic'])->id,
            'category_id' => Category::create(['name' => 'Floor', 'code_prefix' => 'PIS'])->id,
            'unit_type_id' => UnitType::create(['name' => 'pieza'])->id,
            'name' => 'Decorado Talavera',
            'purchase_unit' => 'pieza',
        ]);

        return ProductVariant::create([
            'product_id' => $product->id,
            'code' => 'PIS-TALAVERA-AZUL-20X20',
            'color' => 'Azul',
            'size' => '20x20',
            'price_per_m2' => null,
            'price_per_box' => $pricePerPiece,
            'm2_per_box' => null,
            'stock_boxes' => $stock,
        ]);
    }

    private function createProduct(string $unitTypeName): Product
    {
        return Product::create([
            'supplier_id' => Supplier::create(['name' => 'Interceramic'])->id,
            'category_id' => Category::create(['name' => 'Floor', 'code_prefix' => 'PIS'])->id,
            'unit_type_id' => UnitType::firstOrCreate(['name' => $unitTypeName])->id,
            'name' => 'Decorado Talavera',
            'purchase_unit' => $unitTypeName === 'pieza' ? 'pieza' : 'caja',
        ]);
    }

    public function test_it_creates_a_piece_variant_with_only_price_per_box(): void
    {
        // Ambos tipos existen, como en el seeder: la regla no depende del orden de ids.
        UnitType::create(['name' => 'm2']);
        $product = $this->createProduct('pieza');

        $response = $this->postJson('/api/product-variants', [
            'product_id' => $product->id,
            'color' => 'Azul',
            'size' => '20x20',
            'price_per_box' => 85.50,
        ])->assertCreated();

        $this->assertDatabaseHas('product_variants', [
            'id' => $response->json('data.id'),
            'price_per_m2' => null,
            'm2_per_box' => null,
        ]);
    }

    public function test_it_rejects_m2_attributes_on_a_piece_variant(): void
    {
        $product = $this->createProduct('pieza');

        $this->postJson('/api/product-variants', [
            'product_id' => $product->id,
            'color' => 'Azul',
            'size' => '20x20',
            'price_per_box' => 85.50,
            'price_per_m2' => 100.00,
            'm2_per_box' => 1.44,
            'pei' => 'IV',
            'ett' => 3,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['price_per_m2', 'm2_per_box', 'pei', 'ett']);

        $this->assertDatabaseCount('product_variants', 0);
    }

    public function test_it_requires_price_per_box_on_a_piece_variant(): void
    {
        $product = $this->createProduct('pieza');

        $this->postJson('/api/product-variants', [
            'product_id' => $product->id,
            'color' => 'Azul',
            'size' => '20x20',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['price_per_box'])
            ->assertJsonMissingValidationErrors(['price_per_m2']);
    }

    public function test_m2_variant_rules_are_unchanged(): void
    {
        UnitType::create(['name' => 'pieza']);
        $product = $this->createProduct('m2');

        $this->postJson('/api/product-variants', [
            'product_id' => $product->id,
            'color' => 'Taupe',
            'size' => '60x120',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['price_per_m2', 'price_per_box']);

        $this->postJson('/api/product-variants', [
            'product_id' => $product->id,
            'color' => 'Taupe',
            'size' => '60x120',
            'price_per_m2' => 359.00,
            'price_per_box' => 516.96,
            'm2_per_box' => 1.44,
            'pei' => 'IV',
            'ett' => 3,
        ])->assertCreated();
    }

    public function test_unknown_product_only_reports_the_product_error(): void
    {
        UnitType::create(['name' => 'pieza']);

        $this->postJson('/api/product-variants', [
            'product_id' => 999,
            'color' => 'Taupe',
            'size' => '60x120',
            'price_per_m2' => 359.00,
            'price_per_box' => 516.96,
            'pei' => 'IV',
        ])->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['product_id']);
    }

    public function test_it_rejects_moving_a_piece_variant_to_an_m2_product(): void
    {
        $variant = $this->createPieceVariant();
        $m2Product = $this->createProduct('m2');

        $this->putJson("/api/product-variants/{$variant->id}", [
            'product_id' => $m2Product->id,
            'price_per_box' => 90.00,
        ])->assertUnprocessable()
            ->assertExactJson(['message' => 'No se puede mover una variante a un producto con una unidad de venta distinta.']);

        $variant->refresh();
        $this->assertNotSame($m2Product->id, $variant->product_id);
        $this->assertEqualsWithDelta(85.50, (float) $variant->price_per_box, 0.0001);
    }

    public function test_it_rejects_moving_an_m2_variant_to_a_piece_product(): void
    {
        $m2Product = $this->createProduct('m2');
        $variant = ProductVariant::create([
            'product_id' => $m2Product->id,
            'code' => 'PIS-DECORADO-TAUPE-60X120',
            'color' => 'Taupe',
            'size' => '60x120',
            'price_per_m2' => 359.00,
            'price_per_box' => 516.96,
            'm2_per_box' => 1.44,
        ]);
        $pieceProduct = $this->createProduct('pieza');

        $this->putJson("/api/product-variants/{$variant->id}", [
            'product_id' => $pieceProduct->id,
        ])->assertUnprocessable()
            ->assertExactJson(['message' => 'No se puede mover una variante a un producto con una unidad de venta distinta.']);

        $this->assertSame($m2Product->id, $variant->fresh()->product_id);
    }

    public function test_it_moves_a_piece_variant_to_another_piece_product(): void
    {
        $variant = $this->createPieceVariant();
        $otherPieceProduct = $this->createProduct('pieza');

        $this->putJson("/api/product-variants/{$variant->id}", [
            'product_id' => $otherPieceProduct->id,
        ])->assertOk();

        $this->assertSame($otherPieceProduct->id, $variant->fresh()->product_id);
    }

    public function test_it_rejects_m2_attributes_when_editing_a_piece_variant(): void
    {
        $variant = $this->createPieceVariant();

        foreach (['price_per_m2' => 100.00, 'm2_per_box' => 1.44, 'pei' => 'IV', 'ett' => 3] as $field => $value) {
            $this->putJson("/api/product-variants/{$variant->id}", [$field => $value])
                ->assertUnprocessable()
                ->assertJsonMissingValidationErrors()
                ->assertJsonPath('message', "La variante {$variant->code} se vende por pieza: no lleva precio por m², m² por caja, PEI ni ETT.");
        }

        $variant->refresh();
        $this->assertNull($variant->price_per_m2);
        $this->assertNull($variant->m2_per_box);
        $this->assertNull($variant->pei);
        $this->assertNull($variant->ett);
    }

    public function test_it_edits_only_price_per_box_on_a_piece_variant(): void
    {
        $variant = $this->createPieceVariant();

        $this->putJson("/api/product-variants/{$variant->id}", [
            'price_per_box' => 92.00,
            'pei' => null,
        ])->assertOk();

        $this->assertEqualsWithDelta(92.00, (float) $variant->fresh()->price_per_box, 0.0001);
    }

    public function test_m2_variant_edits_are_unchanged(): void
    {
        $m2Product = $this->createProduct('m2');
        $variant = ProductVariant::create([
            'product_id' => $m2Product->id,
            'code' => 'PIS-DECORADO-TAUPE-60X120',
            'color' => 'Taupe',
            'size' => '60x120',
            'price_per_m2' => 359.00,
            'price_per_box' => 516.96,
            'm2_per_box' => 1.44,
        ]);

        $this->putJson("/api/product-variants/{$variant->id}", [
            'price_per_m2' => 370.00,
            'm2_per_box' => 1.50,
            'pei' => 'IV',
            'ett' => 3,
        ])->assertOk();

        $this->assertSame('IV', $variant->fresh()->pei);
    }

    public function test_it_rejects_changing_unit_type_from_m2_to_piece_with_variants(): void
    {
        $product = $this->createProduct('m2');
        $pieceUnitType = UnitType::create(['name' => 'pieza']);

        foreach (['TAUPE', 'GRIS'] as $color) {
            ProductVariant::create([
                'product_id' => $product->id,
                'code' => "PIS-DECORADO-{$color}-60X120",
                'color' => $color,
                'size' => '60x120',
                'price_per_m2' => 359.00,
                'price_per_box' => 516.96,
                'm2_per_box' => 1.44,
            ]);
        }

        $this->putJson("/api/products/{$product->id}", ['unit_type_id' => $pieceUnitType->id])
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'No se puede cambiar la unidad de venta: el producto ya tiene 2 variantes registradas.']);

        $this->assertSame($product->unit_type_id, $product->fresh()->unit_type_id);
    }

    public function test_it_rejects_changing_unit_type_from_piece_to_m2_with_variants(): void
    {
        $variant = $this->createPieceVariant();
        $m2UnitType = UnitType::create(['name' => 'm2']);

        $this->putJson("/api/products/{$variant->product_id}", ['unit_type_id' => $m2UnitType->id])
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'No se puede cambiar la unidad de venta: el producto ya tiene 1 variante registrada.']);

        $this->assertTrue($variant->product->fresh()->isSoldByPiece());
    }

    public function test_soft_deleted_variants_do_not_block_a_unit_type_change(): void
    {
        $variant = $this->createPieceVariant();
        $variant->delete();
        $m2UnitType = UnitType::create(['name' => 'm2']);

        $this->putJson("/api/products/{$variant->product_id}", ['unit_type_id' => $m2UnitType->id])
            ->assertOk();

        $this->assertSame($m2UnitType->id, $variant->product->fresh()->unit_type_id);
    }

    public function test_it_changes_unit_type_on_a_product_without_variants(): void
    {
        $product = $this->createProduct('m2');
        $pieceUnitType = UnitType::create(['name' => 'pieza']);

        $this->putJson("/api/products/{$product->id}", ['unit_type_id' => $pieceUnitType->id])
            ->assertOk()
            ->assertJsonPath('data.unit_type', 'pieza');
    }

    public function test_it_keeps_the_same_unit_type_on_a_product_with_variants(): void
    {
        $variant = $this->createPieceVariant();

        $this->putJson("/api/products/{$variant->product_id}", [
            'unit_type_id' => $variant->product->unit_type_id,
            'name' => 'Decorado Talavera Premium',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Decorado Talavera Premium');
    }

    private function createM2Variant(): ProductVariant
    {
        return ProductVariant::create([
            'product_id' => $this->createProduct('m2')->id,
            'code' => 'PIS-DECORADO-TAUPE-60X120',
            'color' => 'Taupe',
            'size' => '60x120',
            'price_per_m2' => 359.00,
            'price_per_box' => 516.96,
            'm2_per_box' => 1.44,
            'stock_boxes' => 10,
        ]);
    }

    public function test_quote_detail_exposes_each_variant_unit_type(): void
    {
        $pieceVariant = $this->createPieceVariant();
        $m2Variant = $this->createM2Variant();

        $quoteId = $this->postJson('/api/quotes', [
            'items' => [
                ['product_variant_id' => $pieceVariant->id, 'boxes' => 3],
                ['product_variant_id' => $m2Variant->id, 'boxes' => 2],
            ],
        ])->assertCreated()->json('data.id');

        $this->getJson("/api/quotes/{$quoteId}")
            ->assertOk()
            ->assertJsonPath('data.items.0.product_variant.product.unit_type', 'pieza')
            ->assertJsonPath('data.items.1.product_variant.product.unit_type', 'm2');
    }

    public function test_sale_detail_exposes_each_variant_unit_type(): void
    {
        $pieceVariant = $this->createPieceVariant();
        $m2Variant = $this->createM2Variant();

        $saleId = $this->postJson('/api/sales', [
            'items' => [
                ['product_variant_id' => $pieceVariant->id, 'boxes' => 3, 'unit_price' => 85.50],
                ['product_variant_id' => $m2Variant->id, 'boxes' => 2, 'unit_price' => 359.00],
            ],
        ])->assertCreated()->json('data.id');

        $this->getJson("/api/sales/{$saleId}")
            ->assertOk()
            ->assertJsonPath('data.items.0.product_variant.product.unit_type', 'pieza')
            ->assertJsonPath('data.items.1.product_variant.product.unit_type', 'm2');
    }

    public function test_it_quotes_a_piece_variant_without_m2_per_box(): void
    {
        $variant = $this->createPieceVariant();

        $response = $this->postJson('/api/quotes', [
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 6]],
        ])->assertCreated();

        $item = $response->json('data.items.0');

        $this->assertSame(6, $item['boxes']);
        $this->assertEqualsWithDelta(6, (float) $item['quantity'], 0.0001);
        $this->assertEqualsWithDelta(85.50, (float) $item['unit_price'], 0.0001);
        $this->assertEqualsWithDelta(513.00, (float) $item['line_total'], 0.0001);
        $this->assertEqualsWithDelta(513.00, (float) $response->json('data.total'), 0.0001);
    }

    public function test_it_updates_a_quote_with_a_piece_variant(): void
    {
        $variant = $this->createPieceVariant();

        $quoteId = $this->postJson('/api/quotes', [
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 1]],
        ])->assertCreated()->json('data.id');

        $response = $this->putJson("/api/quotes/{$quoteId}", [
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 4]],
        ])->assertOk();

        $this->assertEqualsWithDelta(4, (float) $response->json('data.items.0.quantity'), 0.0001);
        $this->assertEqualsWithDelta(342.00, (float) $response->json('data.total'), 0.0001);
    }

    public function test_convert_suggests_the_price_per_piece(): void
    {
        $variant = $this->createPieceVariant();

        $quoteId = $this->postJson('/api/quotes', [
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 3]],
        ])->assertCreated()->json('data.id');

        $item = $this->getJson("/api/quotes/{$quoteId}/convert")->assertOk()->json('data.items.0');

        $this->assertSame(3, $item['boxes']);
        $this->assertEqualsWithDelta(85.50, (float) $item['unit_price'], 0.0001);
    }

    public function test_it_sells_a_piece_variant_and_decrements_stock(): void
    {
        $variant = $this->createPieceVariant(stock: 20);

        $response = $this->postJson('/api/sales', [
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 7, 'unit_price' => 80.00]],
        ])->assertCreated();

        $item = $response->json('data.items.0');

        $this->assertSame(7, $item['boxes']);
        $this->assertEqualsWithDelta(7, (float) $item['quantity'], 0.0001);
        $this->assertEqualsWithDelta(560.00, (float) $item['line_total'], 0.0001);
        $this->assertEqualsWithDelta(560.00, (float) $response->json('data.total'), 0.0001);

        $this->assertSame(13, $variant->fresh()->stock_boxes);
        $this->assertDatabaseHas('stock_movements', [
            'product_variant_id' => $variant->id,
            'type' => 'subtract',
            'quantity' => 7,
            'source' => 'sale',
        ]);
    }

    public function test_it_rejects_a_piece_sale_with_insufficient_stock(): void
    {
        $variant = $this->createPieceVariant(stock: 2);

        $this->postJson('/api/sales', [
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 3, 'unit_price' => 85.50]],
        ])->assertUnprocessable();

        $this->assertSame(2, $variant->fresh()->stock_boxes);
    }

    public function test_pdf_reports_piece_lines_in_units(): void
    {
        $variant = $this->createPieceVariant();

        $saleId = $this->postJson('/api/sales', [
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 5, 'unit_price' => 85.50]],
        ])->assertCreated()->json('data.id');

        $captured = null;
        $this->mock(DocumentPdfGenerator::class, function ($mock) use (&$captured) {
            $mock->shouldReceive('generate')->once()->andReturnUsing(function (array $document) use (&$captured) {
                $captured = $document;

                return '%PDF-fake';
            });
        });

        $this->get("/api/sales/{$saleId}/pdf")->assertOk();

        $this->assertSame('uds', $captured['items'][0]['unit']);

        $html = view('pdf.document', ['document' => $captured])->render();

        $this->assertMatchesRegularExpression('/\b5 uds\./u', $html);
        $this->assertDoesNotMatchRegularExpression('/m²/u', $html);
    }
}
