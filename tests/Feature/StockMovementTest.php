<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SimpleProduct;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\UnitType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class StockMovementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('sanctum.stateful')[0] ?? 'http://localhost:5173');

        $this->actingAs(User::factory()->create());
    }

    private function createVariant(?float $m2PerBox = 1.44, int $stockBoxes = 10): ProductVariant
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
            'code' => 'PIS-CREATO-TAU-60X120',
            'color' => 'Taupe',
            'size' => '60x120',
            'price_per_m2' => 100.00,
            'price_per_box' => 144.00,
            'm2_per_box' => $m2PerBox,
            'stock_boxes' => $stockBoxes,
        ]);
    }

    private function createSimpleProduct(int $stockQuantity = 20): SimpleProduct
    {
        $category = Category::create(['name' => 'Materiales', 'code_prefix' => 'MAT', 'product_form_type' => 'simple']);

        return SimpleProduct::create([
            'category_id' => $category->id,
            'name' => 'Pegazulejo gris 20kg',
            'price' => 189.50,
            'stock_quantity' => $stockQuantity,
        ]);
    }

    public function test_manual_variant_adjustments_record_movements(): void
    {
        $variant = $this->createVariant(stockBoxes: 10);

        $this->patchJson("/api/product-variants/{$variant->id}/stock", ['quantity' => 5, 'type' => 'add'])->assertOk();
        $this->patchJson("/api/product-variants/{$variant->id}/stock", ['quantity' => 3, 'type' => 'subtract'])->assertOk();

        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseHas('stock_movements', [
            'product_variant_id' => $variant->id,
            'simple_product_id' => null,
            'quantity' => 5,
            'type' => 'add',
            'source' => 'manual',
            'sale_id' => null,
            'resulting_stock' => 15,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'product_variant_id' => $variant->id,
            'quantity' => 3,
            'type' => 'subtract',
            'source' => 'manual',
            'resulting_stock' => 12,
        ]);
    }

    public function test_manual_simple_product_adjustment_records_a_movement(): void
    {
        $simpleProduct = $this->createSimpleProduct(stockQuantity: 12);

        $this->patchJson("/api/simple-products/{$simpleProduct->id}/stock", ['quantity' => 12, 'type' => 'subtract'])->assertOk();

        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('stock_movements', [
            'product_variant_id' => null,
            'simple_product_id' => $simpleProduct->id,
            'quantity' => 12,
            'type' => 'subtract',
            'source' => 'manual',
            'sale_id' => null,
            'resulting_stock' => 0,
        ]);
    }

    public function test_rejected_manual_adjustments_record_nothing(): void
    {
        $variant = $this->createVariant(stockBoxes: 2);
        $simpleProduct = $this->createSimpleProduct(stockQuantity: 2);

        $this->patchJson("/api/product-variants/{$variant->id}/stock", ['quantity' => 3, 'type' => 'subtract'])->assertUnprocessable();
        $this->patchJson("/api/simple-products/{$simpleProduct->id}/stock", ['quantity' => 3, 'type' => 'subtract'])->assertUnprocessable();
        $this->patchJson("/api/simple-products/{$simpleProduct->id}/stock", ['quantity' => 3, 'type' => 'invalid'])->assertUnprocessable();

        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_sale_records_one_movement_per_product_in_physical_stock_units(): void
    {
        // Dos líneas de la misma variante: ceil(10/1.44)=7 + ceil(2/1.44)=2 = 9 cajas.
        $variant = $this->createVariant(m2PerBox: 1.44, stockBoxes: 10);
        $simpleProduct = $this->createSimpleProduct(stockQuantity: 20);

        $saleId = $this->postJson('/api/sales', [
            'items' => [
                ['product_variant_id' => $variant->id, 'quantity' => 10, 'unit_price' => 100.00],
                ['product_variant_id' => $variant->id, 'quantity' => 2, 'unit_price' => 100.00],
                ['simple_product_id' => $simpleProduct->id, 'quantity' => 4, 'unit_price' => 189.50],
            ],
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseHas('stock_movements', [
            'product_variant_id' => $variant->id,
            'simple_product_id' => null,
            'quantity' => 9,
            'type' => 'subtract',
            'source' => 'sale',
            'sale_id' => $saleId,
            'resulting_stock' => 1,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'product_variant_id' => null,
            'simple_product_id' => $simpleProduct->id,
            'quantity' => 4,
            'type' => 'subtract',
            'source' => 'sale',
            'sale_id' => $saleId,
            'resulting_stock' => 16,
        ]);
    }

    public function test_rejected_sale_records_nothing(): void
    {
        $variant = $this->createVariant(m2PerBox: 1.44, stockBoxes: 10);
        $simpleProduct = $this->createSimpleProduct(stockQuantity: 2);

        $this->postJson('/api/sales', [
            'items' => [
                ['product_variant_id' => $variant->id, 'quantity' => 2, 'unit_price' => 100.00],
                ['simple_product_id' => $simpleProduct->id, 'quantity' => 3, 'unit_price' => 189.50],
            ],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_record_rejects_a_stockable_without_id_and_leaves_stock_untouched(): void
    {
        // Sin id, el movimiento no apuntaría ni a variante ni a producto simple.
        $unsaved = new SimpleProduct(['stock_quantity' => 5]);

        $this->expectException(LogicException::class);

        try {
            StockMovement::record($unsaved, 'add', 1, 'manual');
        } finally {
            $this->assertSame(5, $unsaved->stock_quantity);
            $this->assertDatabaseCount('stock_movements', 0);
        }
    }

    public function test_record_rejects_a_sale_movement_without_a_sale(): void
    {
        $simpleProduct = $this->createSimpleProduct(stockQuantity: 5);

        $this->expectException(LogicException::class);

        try {
            StockMovement::record($simpleProduct, 'subtract', 1, 'sale');
        } finally {
            $this->assertDatabaseHas('simple_products', ['id' => $simpleProduct->id, 'stock_quantity' => 5]);
            $this->assertDatabaseCount('stock_movements', 0);
        }
    }
}
