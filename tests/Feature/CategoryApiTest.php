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
}
