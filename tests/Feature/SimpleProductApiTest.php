<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\SimpleProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SimpleProductApiTest extends TestCase
{
    use RefreshDatabase;

    private Category $simpleCategory;

    private Category $variantCategory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('sanctum.stateful')[0] ?? 'http://localhost:5173');

        $this->actingAs(User::factory()->create());

        $this->simpleCategory = Category::create(['name' => 'Materiales', 'code_prefix' => 'MAT', 'product_form_type' => 'simple']);
        $this->variantCategory = Category::create(['name' => 'Pisos', 'code_prefix' => 'PIS']);
    }

    private function createSimpleProduct(array $overrides = []): SimpleProduct
    {
        return SimpleProduct::create(array_merge([
            'category_id' => $this->simpleCategory->id,
            'name' => 'Pegazulejo gris 20kg',
            'price' => 189.50,
            'stock_quantity' => 12,
        ], $overrides));
    }

    public function test_it_lists_simple_products(): void
    {
        $this->createSimpleProduct();
        $this->createSimpleProduct(['name' => 'Boquilla blanca 5kg']);

        $this->getJson('/api/simple-products')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['name' => 'Pegazulejo gris 20kg'])
            ->assertJsonFragment(['name' => 'Boquilla blanca 5kg']);
    }

    public function test_it_shows_a_simple_product(): void
    {
        $simpleProduct = $this->createSimpleProduct(['description' => 'Saco de 20kg']);

        $this->getJson("/api/simple-products/{$simpleProduct->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Pegazulejo gris 20kg')
            ->assertJsonPath('data.price', '189.50')
            ->assertJsonPath('data.description', 'Saco de 20kg')
            ->assertJsonPath('data.stock_quantity', 12);
    }

    public function test_it_creates_a_simple_product(): void
    {
        $this->postJson('/api/simple-products', [
            'category_id' => $this->simpleCategory->id,
            'name' => 'Pegazulejo gris 20kg',
            'price' => 189.50,
            'description' => 'Saco de 20kg',
            'stock_quantity' => 30,
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Pegazulejo gris 20kg')
            ->assertJsonPath('data.stock_quantity', 30);

        $this->assertDatabaseHas('simple_products', [
            'category_id' => $this->simpleCategory->id,
            'name' => 'Pegazulejo gris 20kg',
            'stock_quantity' => 30,
        ]);
    }

    public function test_it_defaults_stock_quantity_to_zero_on_create(): void
    {
        $this->postJson('/api/simple-products', [
            'category_id' => $this->simpleCategory->id,
            'name' => 'Pegazulejo gris 20kg',
            'price' => 189.50,
        ])->assertCreated()
            ->assertJsonPath('data.stock_quantity', 0)
            ->assertJsonPath('data.description', null);
    }

    public function test_it_requires_category_name_and_price_to_create(): void
    {
        $this->postJson('/api/simple-products', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id', 'name', 'price']);
    }

    public function test_it_rejects_a_nonexistent_category_on_create(): void
    {
        $this->postJson('/api/simple-products', [
            'category_id' => 9999,
            'name' => 'Pegazulejo gris 20kg',
            'price' => 189.50,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id']);
    }

    public function test_it_rejects_a_variant_category_on_create(): void
    {
        $this->postJson('/api/simple-products', [
            'category_id' => $this->variantCategory->id,
            'name' => 'Pegazulejo gris 20kg',
            'price' => 189.50,
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'La categoría seleccionada no admite productos simples.');

        $this->assertDatabaseCount('simple_products', 0);
    }

    public function test_it_updates_a_simple_product(): void
    {
        $simpleProduct = $this->createSimpleProduct();

        $this->putJson("/api/simple-products/{$simpleProduct->id}", [
            'name' => 'Pegazulejo blanco 20kg',
            'price' => 205.00,
            'description' => 'Para piso y muro',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Pegazulejo blanco 20kg')
            ->assertJsonPath('data.price', '205.00');

        $this->assertDatabaseHas('simple_products', [
            'id' => $simpleProduct->id,
            'name' => 'Pegazulejo blanco 20kg',
            'description' => 'Para piso y muro',
        ]);
    }

    public function test_it_ignores_stock_quantity_on_update(): void
    {
        $simpleProduct = $this->createSimpleProduct();

        $this->putJson("/api/simple-products/{$simpleProduct->id}", [
            'name' => 'Pegazulejo blanco 20kg',
            'stock_quantity' => 999,
        ])->assertOk()
            ->assertJsonPath('data.stock_quantity', 12);

        $this->assertDatabaseHas('simple_products', [
            'id' => $simpleProduct->id,
            'stock_quantity' => 12,
        ]);
    }

    public function test_it_rejects_moving_a_simple_product_to_a_variant_category(): void
    {
        $simpleProduct = $this->createSimpleProduct();

        $this->putJson("/api/simple-products/{$simpleProduct->id}", [
            'category_id' => $this->variantCategory->id,
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'La categoría seleccionada no admite productos simples.');

        $this->assertDatabaseHas('simple_products', [
            'id' => $simpleProduct->id,
            'category_id' => $this->simpleCategory->id,
        ]);
    }

    public function test_it_soft_deletes_a_simple_product(): void
    {
        $simpleProduct = $this->createSimpleProduct();

        $this->deleteJson("/api/simple-products/{$simpleProduct->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('simple_products', ['id' => $simpleProduct->id]);

        $this->getJson('/api/simple-products')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_it_returns_404_for_a_soft_deleted_simple_product(): void
    {
        $simpleProduct = $this->createSimpleProduct();
        $simpleProduct->delete();

        $this->getJson("/api/simple-products/{$simpleProduct->id}")
            ->assertNotFound();
    }

    public function test_it_adds_stock_to_a_simple_product(): void
    {
        $simpleProduct = $this->createSimpleProduct();

        $this->patchJson("/api/simple-products/{$simpleProduct->id}/stock", [
            'quantity' => 8,
            'type' => 'add',
        ])->assertOk()
            ->assertJsonPath('data.stock_quantity', 20);

        $this->assertDatabaseHas('simple_products', ['id' => $simpleProduct->id, 'stock_quantity' => 20]);
    }

    public function test_it_subtracts_stock_from_a_simple_product(): void
    {
        $simpleProduct = $this->createSimpleProduct();

        $this->patchJson("/api/simple-products/{$simpleProduct->id}/stock", [
            'quantity' => 12,
            'type' => 'subtract',
        ])->assertOk()
            ->assertJsonPath('data.stock_quantity', 0);

        $this->assertDatabaseHas('simple_products', ['id' => $simpleProduct->id, 'stock_quantity' => 0]);
    }

    public function test_it_rejects_subtracting_more_stock_than_available(): void
    {
        $simpleProduct = $this->createSimpleProduct();

        $this->patchJson("/api/simple-products/{$simpleProduct->id}/stock", [
            'quantity' => 13,
            'type' => 'subtract',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Stock insuficiente. Disponible: 12 unidades.');

        $this->assertDatabaseHas('simple_products', ['id' => $simpleProduct->id, 'stock_quantity' => 12]);
    }

    public function test_it_validates_the_stock_adjustment_payload(): void
    {
        $simpleProduct = $this->createSimpleProduct();

        $this->patchJson("/api/simple-products/{$simpleProduct->id}/stock", ['type' => 'remove'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity', 'type']);
    }

    public function test_it_rejects_a_non_positive_stock_adjustment_quantity(): void
    {
        $simpleProduct = $this->createSimpleProduct();

        foreach ([['quantity' => -50, 'type' => 'subtract'], ['quantity' => -50, 'type' => 'add'], ['quantity' => 0, 'type' => 'add'], ['quantity' => 0, 'type' => 'subtract']] as $payload) {
            $this->patchJson("/api/simple-products/{$simpleProduct->id}/stock", $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['quantity']);
        }

        $this->assertDatabaseHas('simple_products', ['id' => $simpleProduct->id, 'stock_quantity' => 12]);
    }
}
