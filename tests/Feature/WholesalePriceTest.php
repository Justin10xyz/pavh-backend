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

        foreach (['Borrador', 'Pagada', 'Cancelada'] as $name) {
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
    }
}
