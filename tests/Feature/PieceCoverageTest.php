<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\QuoteStatus;
use App\Models\Supplier;
use App\Models\UnitType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesQuoteCustomers;
use Tests\TestCase;

/**
 * m2_per_piece: m² que cubre una pieza, opcional y solo en pisos por pieza
 * (unit_type "pieza"). Prohibido en pisos por m², y no cambia cómo se cotiza
 * una pieza (quantity = boxes, sin m²).
 */
class PieceCoverageTest extends TestCase
{
    use CreatesQuoteCustomers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('sanctum.stateful')[0] ?? 'http://localhost:5173');

        $this->actingAs(User::factory()->create());

        foreach (['Borrador', 'Pagada', 'Cancelada'] as $name) {
            QuoteStatus::create(['name' => $name]);
        }

        // Ambos tipos existen, como en el seeder: las reglas no dependen del orden de ids.
        foreach (['m2', 'pieza'] as $name) {
            UnitType::create(['name' => $name]);
        }
    }

    private function createProduct(string $unitTypeName): Product
    {
        return Product::create([
            'supplier_id' => Supplier::create(['name' => 'Interceramic'])->id,
            'category_id' => Category::create(['name' => 'Floor', 'code_prefix' => 'PIS'])->id,
            'unit_type_id' => UnitType::where('name', $unitTypeName)->firstOrFail()->id,
            'name' => 'Decorado Talavera',
            'purchase_unit' => $unitTypeName === 'pieza' ? 'pieza' : 'caja',
        ]);
    }

    private function createPieceVariant(?float $m2PerPiece = null): ProductVariant
    {
        return ProductVariant::create([
            'product_id' => $this->createProduct('pieza')->id,
            'code' => 'PIS-TALAVERA-AZUL-20X20',
            'color' => 'Azul',
            'size' => '20X20',
            'price_per_box' => 85.50,
            'm2_per_piece' => $m2PerPiece,
            'stock_boxes' => 20,
        ]);
    }

    private function createM2Variant(): ProductVariant
    {
        return ProductVariant::create([
            'product_id' => $this->createProduct('m2')->id,
            'code' => 'PIS-DECORADO-TAUPE-60X120',
            'color' => 'Taupe',
            'size' => '60X120',
            'price_per_m2' => 359.00,
            'price_per_box' => 516.96,
            'm2_per_box' => 1.44,
        ]);
    }

    public function test_it_creates_a_piece_variant_with_m2_per_piece(): void
    {
        $product = $this->createProduct('pieza');

        $response = $this->postJson('/api/product-variants', [
            'product_id' => $product->id,
            'color' => 'Azul',
            'size' => '20x20',
            'price_per_box' => 85.50,
            'm2_per_piece' => 0.04,
        ])->assertCreated();

        $this->assertSame('0.0400', ProductVariant::findOrFail($response->json('data.id'))->m2_per_piece);
    }

    public function test_it_creates_a_piece_variant_without_m2_per_piece(): void
    {
        $product = $this->createProduct('pieza');

        foreach (['Azul' => [], 'Rojo' => ['m2_per_piece' => null]] as $color => $extra) {
            $response = $this->postJson('/api/product-variants', [
                'product_id' => $product->id,
                'color' => $color,
                'size' => '20x20',
                'price_per_box' => 85.50,
                ...$extra,
            ])->assertCreated()
                ->assertJsonPath('data.m2_per_piece', null);

            $this->assertDatabaseHas('product_variants', [
                'id' => $response->json('data.id'),
                'm2_per_piece' => null,
            ]);
        }
    }

    public function test_it_rejects_m2_per_piece_when_creating_an_m2_variant(): void
    {
        $product = $this->createProduct('m2');

        $this->postJson('/api/product-variants', [
            'product_id' => $product->id,
            'color' => 'Taupe',
            'size' => '60x120',
            'price_per_m2' => 359.00,
            'price_per_box' => 516.96,
            'm2_per_box' => 1.44,
            'm2_per_piece' => 0.72,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['m2_per_piece']);

        $this->assertDatabaseCount('product_variants', 0);
    }

    public function test_it_accepts_a_null_m2_per_piece_when_creating_an_m2_variant(): void
    {
        $product = $this->createProduct('m2');

        $this->postJson('/api/product-variants', [
            'product_id' => $product->id,
            'color' => 'Taupe',
            'size' => '60x120',
            'price_per_m2' => 359.00,
            'price_per_box' => 516.96,
            'm2_per_box' => 1.44,
            'm2_per_piece' => null,
        ])->assertCreated();
    }

    public function test_it_rejects_m2_per_piece_when_editing_an_m2_variant(): void
    {
        $variant = $this->createM2Variant();

        $this->putJson("/api/product-variants/{$variant->id}", ['m2_per_piece' => 0.72])
            ->assertUnprocessable()
            ->assertJsonMissingValidationErrors()
            ->assertJsonPath('message', "La variante {$variant->code} se vende por m²: no lleva m² por pieza.");

        $this->assertNull($variant->fresh()->m2_per_piece);
    }

    public function test_m2_variant_edits_accept_a_null_m2_per_piece(): void
    {
        $variant = $this->createM2Variant();

        $this->putJson("/api/product-variants/{$variant->id}", [
            'price_per_m2' => 370.00,
            'm2_per_piece' => null,
        ])->assertOk();

        $this->assertEqualsWithDelta(370.00, (float) $variant->fresh()->price_per_m2, 0.0001);
    }

    public function test_it_edits_m2_per_piece_on_a_piece_variant(): void
    {
        $variant = $this->createPieceVariant();

        $this->putJson("/api/product-variants/{$variant->id}", ['m2_per_piece' => 0.0225])
            ->assertOk()
            ->assertJsonPath('data.m2_per_piece', '0.0225');

        $this->assertSame('0.0225', $variant->fresh()->m2_per_piece);

        $this->putJson("/api/product-variants/{$variant->id}", ['m2_per_piece' => null])
            ->assertOk()
            ->assertJsonPath('data.m2_per_piece', null);

        $this->assertNull($variant->fresh()->m2_per_piece);
    }

    public function test_it_rejects_zero_and_negative_m2_per_piece(): void
    {
        $product = $this->createProduct('pieza');
        $variant = $this->createPieceVariant(0.04);

        foreach ([0, -0.5] as $value) {
            $this->postJson('/api/product-variants', [
                'product_id' => $product->id,
                'color' => 'Azul',
                'size' => '20x20',
                'price_per_box' => 85.50,
                'm2_per_piece' => $value,
            ])->assertUnprocessable()
                ->assertJsonValidationErrors(['m2_per_piece']);

            $this->putJson("/api/product-variants/{$variant->id}", ['m2_per_piece' => $value])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['m2_per_piece']);
        }

        $this->assertSame('0.0400', $variant->fresh()->m2_per_piece);
    }

    public function test_resource_exposes_m2_per_piece_as_a_four_decimal_string(): void
    {
        $variant = $this->createPieceVariant(0.5);

        $this->getJson("/api/product-variants/{$variant->id}")
            ->assertOk()
            ->assertJsonPath('data.m2_per_piece', '0.5000');
    }

    public function test_quoting_a_piece_variant_with_m2_per_piece_still_uses_boxes_as_quantity(): void
    {
        $variant = $this->createPieceVariant(0.04);

        $response = $this->postJson('/api/quotes', [
            'customer_id' => $this->quoteCustomerId(),
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 6]],
        ])->assertCreated();

        $item = $response->json('data.items.0');

        $this->assertSame(6, $item['boxes']);
        $this->assertEqualsWithDelta(6, (float) $item['quantity'], 0.0001);
        $this->assertEqualsWithDelta(85.50, (float) $item['unit_price'], 0.0001);
        $this->assertEqualsWithDelta(513.00, (float) $item['line_total'], 0.0001);
        $this->assertSame('0.0400', $item['product_variant']['m2_per_piece']);
    }
}
