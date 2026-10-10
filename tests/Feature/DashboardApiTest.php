<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SimpleProduct;
use App\Models\Supplier;
use App\Models\UnitType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    private int $folioSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('sanctum.stateful')[0] ?? 'http://localhost:5173');

        $this->actingAs(User::factory()->create());
    }

    private function createVariant(string $color): ProductVariant
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
            'price_per_m2' => 100,
            'price_per_box' => 144,
            'm2_per_box' => 1.44,
            'stock_boxes' => 10,
        ]);
    }

    private function createSimpleProduct(string $name): SimpleProduct
    {
        $category = Category::create(['name' => 'Materiales', 'code_prefix' => 'MAT', 'product_form_type' => 'simple']);

        return SimpleProduct::create([
            'category_id' => $category->id,
            'name' => $name,
            'price' => 189.50,
            'stock_quantity' => 20,
        ]);
    }

    /**
     * @param  array<int, array{0: ProductVariant|SimpleProduct, 1: float, 2: float}>  $lines  [producto, cantidad, precio unitario]
     */
    private function createSaleAt(string $createdAt, array $lines): Sale
    {
        $sale = $this->travelTo($createdAt, fn () => Sale::create([
            'folio' => sprintf('V-%04d', ++$this->folioSequence),
            'subtotal' => 0,
            'total' => 0,
        ]));

        foreach ($lines as [$product, $quantity, $unitPrice]) {
            $sale->items()->create([
                'product_variant_id' => $product instanceof ProductVariant ? $product->id : null,
                'simple_product_id' => $product instanceof SimpleProduct ? $product->id : null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => round($quantity * $unitPrice, 2),
            ]);
        }

        return $sale;
    }

    public function test_it_ranks_variants_and_simple_products_together_by_revenue(): void
    {
        $taupe = $this->createVariant('Taupe');
        $blanco = $this->createVariant('Blanco');
        $pegazulejo = $this->createSimpleProduct('Pegazulejo gris 20kg');

        $this->createSaleAt('2026-10-02 10:00:00', [
            [$taupe, 10, 100],
            [$pegazulejo, 4, 189.50],
        ]);
        $this->createSaleAt('2026-10-03 12:00:00', [
            [$pegazulejo, 8, 189.50],
            [$blanco, 3, 120],
            [$taupe, 5, 100],
        ]);

        // Taupe vende más cantidad (15 m² vs 12 uds) pero Pegazulejo deja más
        // dinero (2274 vs 1500): manda el revenue.
        $this->getJson('/api/dashboard/top-products')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertExactJson(['data' => [
                [
                    'name' => 'Pegazulejo gris 20kg',
                    'type' => 'simple',
                    'product_variant_id' => null,
                    'simple_product_id' => $pegazulejo->id,
                    'quantity_sold' => 12,
                    'revenue' => 2274,
                ],
                [
                    'name' => 'Creato Taupe 60x120',
                    'type' => 'variant',
                    'product_variant_id' => $taupe->id,
                    'simple_product_id' => null,
                    'quantity_sold' => 15,
                    'revenue' => 1500,
                ],
                [
                    'name' => 'Creato Blanco 60x120',
                    'type' => 'variant',
                    'product_variant_id' => $blanco->id,
                    'simple_product_id' => null,
                    'quantity_sold' => 3,
                    'revenue' => 360,
                ],
            ]]);
    }

    public function test_it_only_counts_sales_within_the_inclusive_date_range(): void
    {
        $taupe = $this->createVariant('Taupe');
        $pegazulejo = $this->createSimpleProduct('Pegazulejo gris 20kg');

        $this->createSaleAt('2026-09-30 23:59:00', [[$taupe, 50, 100]]);
        $this->createSaleAt('2026-10-01 00:00:00', [[$taupe, 2, 100]]);
        $this->createSaleAt('2026-10-03 23:59:00', [[$pegazulejo, 5, 189.50]]);
        $this->createSaleAt('2026-10-04 00:00:00', [[$pegazulejo, 50, 189.50]]);

        $this->getJson('/api/dashboard/top-products?from=2026-10-01&to=2026-10-03')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Pegazulejo gris 20kg')
            ->assertJsonPath('data.0.quantity_sold', 5)
            ->assertJsonPath('data.1.name', 'Creato Taupe 60x120')
            ->assertJsonPath('data.1.quantity_sold', 2);
    }

    public function test_it_excludes_soft_deleted_sales(): void
    {
        $taupe = $this->createVariant('Taupe');

        $this->createSaleAt('2026-10-02 10:00:00', [[$taupe, 2, 100]]);
        $this->createSaleAt('2026-10-02 11:00:00', [[$taupe, 50, 100]])->delete();

        $this->getJson('/api/dashboard/top-products')
            ->assertOk()
            ->assertJsonPath('data.0.quantity_sold', 2);
    }

    public function test_it_defaults_to_five_products_and_respects_the_limit(): void
    {
        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $index => $color) {
            $this->createSaleAt('2026-10-02 10:00:00', [[$this->createVariant($color), 10 - $index, 100]]);
        }

        $this->getJson('/api/dashboard/top-products')
            ->assertOk()
            ->assertJsonCount(5, 'data');

        $this->getJson('/api/dashboard/top-products?limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Creato A 60x120')
            ->assertJsonPath('data.1.name', 'Creato B 60x120');
    }

    public function test_it_returns_an_empty_array_when_there_are_no_sales_in_the_range(): void
    {
        $this->createSaleAt('2026-09-15 10:00:00', [[$this->createVariant('Taupe'), 10, 100]]);

        $this->getJson('/api/dashboard/top-products?from=2026-10-01&to=2026-10-31')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_it_keeps_soft_deleted_products_in_the_ranking(): void
    {
        $taupe = $this->createVariant('Taupe');
        $pegazulejo = $this->createSimpleProduct('Pegazulejo gris 20kg');

        $this->createSaleAt('2026-10-02 10:00:00', [[$taupe, 10, 100], [$pegazulejo, 4, 189.50]]);

        $taupe->product->delete();
        $taupe->delete();
        $pegazulejo->delete();

        $this->getJson('/api/dashboard/top-products')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Creato Taupe 60x120')
            ->assertJsonPath('data.1.name', 'Pegazulejo gris 20kg');
    }

    public function test_it_rejects_invalid_filters(): void
    {
        $this->getJson('/api/dashboard/top-products?from=01/10/2026')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('from');

        $this->getJson('/api/dashboard/top-products?limit=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('limit');
    }

    public function test_it_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/dashboard/top-products')->assertUnauthorized();
    }
}
