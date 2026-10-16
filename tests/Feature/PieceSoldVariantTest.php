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

        foreach (['Borrador', 'Convertida', 'Cancelada'] as $name) {
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
