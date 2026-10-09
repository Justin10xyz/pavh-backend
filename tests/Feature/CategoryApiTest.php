<?php

namespace Tests\Feature;

use App\Models\Category;
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
}
