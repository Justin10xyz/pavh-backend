<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\SimpleProduct;
use App\Models\Supplier;
use App\Models\UnitType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('sanctum.stateful')[0] ?? 'http://localhost:5173');

        $this->actingAs(User::factory()->create());
    }

    public function test_it_lists_categories(): void
    {
        Category::create(['name' => 'Pisos', 'code_prefix' => 'PI']);
        Category::create(['name' => 'Azulejos', 'code_prefix' => 'AZ']);

        $this->getJson('/api/categories')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['name' => 'Pisos'])
            ->assertJsonFragment(['name' => 'Azulejos']);
    }

    public function test_it_creates_a_category(): void
    {
        $this->postJson('/api/categories', ['name' => 'Slabs', 'code_prefix' => 'SLA'])
            ->assertCreated()
            ->assertJsonFragment(['name' => 'Slabs']);

        $this->assertDatabaseHas('categories', ['name' => 'Slabs', 'code_prefix' => 'SLA']);
    }

    public function test_it_requires_name_and_code_prefix_to_create_a_category(): void
    {
        $this->postJson('/api/categories', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'code_prefix']);
    }

    public function test_it_rejects_a_duplicate_category_name(): void
    {
        Category::create(['name' => 'Pisos', 'code_prefix' => 'PI']);

        $this->postJson('/api/categories', ['name' => 'Pisos', 'code_prefix' => 'OTRO'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_it_rejects_a_duplicate_category_code_prefix(): void
    {
        Category::create(['name' => 'Pisos', 'code_prefix' => 'PI']);

        $this->postJson('/api/categories', ['name' => 'Otra Categoria', 'code_prefix' => 'PI'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code_prefix']);
    }

    public function test_it_updates_a_category(): void
    {
        $category = Category::create(['name' => 'Pisos', 'code_prefix' => 'PI']);

        $this->putJson("/api/categories/{$category->id}", ['name' => 'Pisos Cerámicos', 'code_prefix' => 'PC'])
            ->assertOk()
            ->assertJsonFragment(['name' => 'Pisos Cerámicos']);

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'Pisos Cerámicos', 'code_prefix' => 'PC']);
    }

    public function test_it_rejects_a_duplicate_category_name_on_update(): void
    {
        Category::create(['name' => 'Pisos', 'code_prefix' => 'PI']);
        $category = Category::create(['name' => 'Azulejos', 'code_prefix' => 'AZ']);

        $this->putJson("/api/categories/{$category->id}", ['name' => 'Pisos', 'code_prefix' => 'AZ'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_it_rejects_a_duplicate_category_code_prefix_on_update(): void
    {
        Category::create(['name' => 'Pisos', 'code_prefix' => 'PI']);
        $category = Category::create(['name' => 'Azulejos', 'code_prefix' => 'AZ']);

        $this->putJson("/api/categories/{$category->id}", ['name' => 'Azulejos', 'code_prefix' => 'PI'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code_prefix']);
    }

    public function test_it_defaults_product_form_type_to_variant_on_create(): void
    {
        $this->postJson('/api/categories', ['name' => 'Slabs', 'code_prefix' => 'SLA'])
            ->assertCreated()
            ->assertJsonPath('data.product_form_type', 'variant');
    }

    public function test_it_creates_a_category_with_simple_product_form_type(): void
    {
        $this->postJson('/api/categories', ['name' => 'Materiales', 'code_prefix' => 'MAT', 'product_form_type' => 'simple'])
            ->assertCreated()
            ->assertJsonPath('data.product_form_type', 'simple');

        $this->assertDatabaseHas('categories', ['name' => 'Materiales', 'product_form_type' => 'simple']);
    }

    public function test_it_rejects_an_invalid_product_form_type_on_create(): void
    {
        $this->postJson('/api/categories', ['name' => 'Materiales', 'code_prefix' => 'MAT', 'product_form_type' => 'otro'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['product_form_type']);
    }

    public function test_it_updates_the_product_form_type_of_a_category(): void
    {
        $category = Category::create(['name' => 'Materiales', 'code_prefix' => 'MAT']);

        $this->putJson("/api/categories/{$category->id}", ['name' => 'Materiales', 'code_prefix' => 'MAT', 'product_form_type' => 'simple'])
            ->assertOk()
            ->assertJsonPath('data.product_form_type', 'simple');

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'product_form_type' => 'simple']);
    }

    public function test_it_rejects_an_invalid_product_form_type_on_update(): void
    {
        $category = Category::create(['name' => 'Materiales', 'code_prefix' => 'MAT']);

        $this->putJson("/api/categories/{$category->id}", ['name' => 'Materiales', 'code_prefix' => 'MAT', 'product_form_type' => 'otro'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['product_form_type']);
    }

    public function test_it_blocks_changing_from_variant_to_simple_when_the_category_has_products(): void
    {
        $category = Category::create(['name' => 'Pisos', 'code_prefix' => 'PI']);
        $this->createProduct($category);
        $this->createProduct($category);

        $this->putJson("/api/categories/{$category->id}", ['name' => 'Pisos', 'code_prefix' => 'PI', 'product_form_type' => 'simple'])
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $message) => str_contains($message, '2 productos con variantes'));

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'product_form_type' => 'variant']);
    }

    public function test_it_blocks_changing_from_simple_to_variant_when_the_category_has_simple_products(): void
    {
        $category = Category::create(['name' => 'Materiales', 'code_prefix' => 'MAT', 'product_form_type' => 'simple']);
        $this->createSimpleProduct($category);

        $this->putJson("/api/categories/{$category->id}", ['name' => 'Materiales', 'code_prefix' => 'MAT', 'product_form_type' => 'variant'])
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $message) => str_contains($message, '1 producto general'));

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'product_form_type' => 'simple']);
    }

    public function test_it_allows_changing_the_product_form_type_when_the_category_has_no_products(): void
    {
        $category = Category::create(['name' => 'Materiales', 'code_prefix' => 'MAT', 'product_form_type' => 'simple']);

        $this->putJson("/api/categories/{$category->id}", ['name' => 'Materiales', 'code_prefix' => 'MAT', 'product_form_type' => 'variant'])
            ->assertOk()
            ->assertJsonPath('data.product_form_type', 'variant');

        $this->putJson("/api/categories/{$category->id}", ['name' => 'Materiales', 'code_prefix' => 'MAT', 'product_form_type' => 'simple'])
            ->assertOk()
            ->assertJsonPath('data.product_form_type', 'simple');
    }

    public function test_it_ignores_soft_deleted_products_when_changing_the_product_form_type(): void
    {
        $category = Category::create(['name' => 'Pisos', 'code_prefix' => 'PI']);
        $this->createProduct($category)->delete();

        $this->putJson("/api/categories/{$category->id}", ['name' => 'Pisos', 'code_prefix' => 'PI', 'product_form_type' => 'simple'])
            ->assertOk()
            ->assertJsonPath('data.product_form_type', 'simple');
    }

    public function test_it_allows_updating_a_category_with_products_when_product_form_type_does_not_change(): void
    {
        $category = Category::create(['name' => 'Pisos', 'code_prefix' => 'PI']);
        $this->createProduct($category);

        $this->putJson("/api/categories/{$category->id}", ['name' => 'Pisos Cerámicos', 'code_prefix' => 'PC', 'product_form_type' => 'variant'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Pisos Cerámicos');

        $this->putJson("/api/categories/{$category->id}", ['name' => 'Pisos', 'code_prefix' => 'PI'])
            ->assertOk()
            ->assertJsonPath('data.product_form_type', 'variant');
    }

    private function createProduct(Category $category): Product
    {
        return Product::create([
            'supplier_id' => Supplier::firstOrCreate(['name' => 'Interceramic'])->id,
            'category_id' => $category->id,
            'unit_type_id' => UnitType::firstOrCreate(['name' => 'm2'])->id,
            'name' => 'Creato',
            'purchase_unit' => 'Caja',
        ]);
    }

    private function createSimpleProduct(Category $category): SimpleProduct
    {
        return SimpleProduct::create([
            'category_id' => $category->id,
            'name' => 'Pegazulejo gris 20 kg',
            'price' => 185,
        ]);
    }
}
