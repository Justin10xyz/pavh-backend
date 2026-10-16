<?php

namespace Tests\Feature;

use App\Models\CommissionCategory;
use App\Models\User;
use Database\Seeders\CommissionCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommissionCategoryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('sanctum.stateful')[0] ?? 'http://localhost:5173');

        $this->actingAs(User::factory()->create());
    }

    public function test_it_lists_commission_categories(): void
    {
        CommissionCategory::create(['code' => 'Vo']);
        CommissionCategory::create(['code' => 'N']);
        CommissionCategory::create(['code' => 'A']);

        $this->getJson('/api/commission-categories')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonFragment(['code' => 'Vo'])
            ->assertJsonFragment(['code' => 'N'])
            ->assertJsonFragment(['code' => 'A']);
    }

    public function test_it_lists_commission_categories_from_highest_to_lowest_commission_with_untiered_last(): void
    {
        CommissionCategory::create(['code' => 'X']);
        CommissionCategory::create(['code' => 'R', 'name' => 'Rojo', 'tier' => 5]);
        CommissionCategory::create(['code' => 'Vo', 'name' => 'Verde oscuro', 'tier' => 1]);
        CommissionCategory::create(['code' => 'A', 'name' => 'Amarillo', 'tier' => 3]);

        $response = $this->getJson('/api/commission-categories')->assertOk();

        $this->assertSame(['Vo', 'A', 'R', 'X'], array_column($response->json('data'), 'code'));
        $response->assertJsonFragment(['code' => 'Vo', 'name' => 'Verde oscuro', 'tier' => 1]);
    }

    public function test_seeder_creates_the_five_codes_and_is_idempotent(): void
    {
        $this->seed(CommissionCategorySeeder::class);
        $this->seed(CommissionCategorySeeder::class);

        $this->assertSame(
            ['Vo' => 1, 'Vc' => 2, 'A' => 3, 'N' => 4, 'R' => 5],
            CommissionCategory::ordered()->pluck('tier', 'code')->all(),
        );
    }
}
