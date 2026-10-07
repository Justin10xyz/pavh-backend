<?php

namespace Tests\Feature;

use App\Models\CommissionCategory;
use App\Models\User;
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
}
