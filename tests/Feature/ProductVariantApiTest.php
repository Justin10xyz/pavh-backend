<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\UnitType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductVariantApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('sanctum.stateful')[0] ?? 'http://localhost:5173');

        $this->actingAs(User::factory()->create());
    }

    public function test_full_product_and_variant_lifecycle(): void
    {
        $supplier = Supplier::create(['name' => 'Interceramic']);
        $category = Category::create(['name' => 'Floor', 'code_prefix' => 'PIS']);
        $unitType = UnitType::create(['name' => 'm2']);

        $productResponse = $this->postJson('/api/products', [
            'name' => 'Creato',
            'supplier_id' => $supplier->id,
            'category_id' => $category->id,
            'unit_type_id' => $unitType->id,
            'purchase_unit' => 'caja',
        ])->assertCreated();

        $productId = $productResponse->json('data.id');

        $variantResponse = $this->postJson('/api/product-variants', [
            'product_id' => $productId,
            'color' => 'Taupe',
            'size' => '60x120',
            'price_per_m2' => 359.00,
            'price_per_box' => 516.96,
            'minimum_stock' => 10,
        ])->assertCreated();

        $variantResponse->assertJsonPath('data.code', 'PIS-CREATO-TAU-60X120');
        $variantResponse->assertJsonPath('data.stock_boxes', 0);

        $variantId = $variantResponse->json('data.id');

        $this->assertDatabaseHas('product_variants', [
            'id' => $variantId,
            'code' => 'PIS-CREATO-TAU-60X120',
            'stock_boxes' => 0,
        ]);

        $this->patchJson("/api/product-variants/{$variantId}/stock", [
            'quantity' => 5,
            'type' => 'add',
        ])->assertOk()
            ->assertJsonPath('data.stock_boxes', 5)
            ->assertJsonPath('data.low_stock', true);

        $this->patchJson("/api/product-variants/{$variantId}/stock", [
            'quantity' => 20,
            'type' => 'add',
        ])->assertOk()
            ->assertJsonPath('data.stock_boxes', 25)
            ->assertJsonPath('data.low_stock', false);

        $this->postJson('/api/product-variants', [
            'product_id' => $productId,
            'color' => 'Gris',
            'size' => '60x120',
            'price_per_m2' => 359.00,
            'price_per_box' => 516.96,
        ])->assertCreated();

        $this->deleteJson("/api/product-variants/{$variantId}")->assertNoContent();

        $this->getJson('/api/product-variants')
            ->assertOk()
            ->assertJsonMissing(['id' => $variantId]);

        $this->assertSoftDeleted('product_variants', ['id' => $variantId]);

        $this->assertDatabaseHas('product_variants', ['id' => $variantId]);
    }

    public function test_stock_request_rejects_invalid_type(): void
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

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'code' => 'PIS-CREATO-TAU-60X120',
            'color' => 'Taupe',
            'size' => '60x120',
            'price_per_m2' => 359.00,
            'price_per_box' => 516.96,
        ]);

        $this->patchJson("/api/product-variants/{$variant->id}/stock", [
            'quantity' => 5,
            'type' => 'invalid',
        ])->assertStatus(422);
    }

    public function test_stock_request_rejects_subtract_exceeding_current_stock(): void
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

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'code' => 'PIS-CREATO-TAU-60X120',
            'color' => 'Taupe',
            'size' => '60x120',
            'price_per_m2' => 359.00,
            'price_per_box' => 516.96,
            'stock_boxes' => 5,
        ]);

        $this->patchJson("/api/product-variants/{$variant->id}/stock", [
            'quantity' => 10,
            'type' => 'subtract',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Stock insuficiente. Disponible: 5 cajas.');

        $this->assertDatabaseHas('product_variants', [
            'id' => $variant->id,
            'stock_boxes' => 5,
        ]);
    }

    public function test_stock_request_rejects_non_positive_quantity(): void
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

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'code' => 'PIS-CREATO-TAU-60X120',
            'color' => 'Taupe',
            'size' => '60x120',
            'price_per_m2' => 359.00,
            'price_per_box' => 516.96,
            'stock_boxes' => 5,
        ]);

        foreach ([['quantity' => -50, 'type' => 'subtract'], ['quantity' => -50, 'type' => 'add'], ['quantity' => 0, 'type' => 'add'], ['quantity' => 0, 'type' => 'subtract']] as $payload) {
            $this->patchJson("/api/product-variants/{$variant->id}/stock", $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['quantity']);
        }

        $this->assertDatabaseHas('product_variants', [
            'id' => $variant->id,
            'stock_boxes' => 5,
        ]);
    }

    public function test_destroy_deletes_variant_when_product_has_others(): void
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

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'code' => 'PIS-CREATO-TAU-60X120',
            'color' => 'Taupe',
            'size' => '60x120',
            'price_per_m2' => 359.00,
            'price_per_box' => 516.96,
        ]);

        ProductVariant::create([
            'product_id' => $product->id,
            'code' => 'PIS-CREATO-GRI-60X120',
            'color' => 'Gris',
            'size' => '60x120',
            'price_per_m2' => 359.00,
            'price_per_box' => 516.96,
        ]);

        $this->deleteJson("/api/product-variants/{$variant->id}")->assertNoContent();

        $this->assertSoftDeleted('product_variants', ['id' => $variant->id]);
    }

    public function test_destroy_rejects_deleting_last_active_variant(): void
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

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'code' => 'PIS-CREATO-TAU-60X120',
            'color' => 'Taupe',
            'size' => '60x120',
            'price_per_m2' => 359.00,
            'price_per_box' => 516.96,
        ]);

        $this->deleteJson("/api/product-variants/{$variant->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'No se puede eliminar la última variante de un producto.');

        $this->assertDatabaseHas('product_variants', [
            'id' => $variant->id,
            'deleted_at' => null,
        ]);
    }
}
