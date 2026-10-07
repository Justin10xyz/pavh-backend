<?php

namespace Tests\Feature;

use App\Models\UnitType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnitTypeApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('sanctum.stateful')[0] ?? 'http://localhost:5173');

        $this->actingAs(User::factory()->create());
    }

    public function test_it_lists_unit_types(): void
    {
        UnitType::create(['name' => 'Caja']);
        UnitType::create(['name' => 'Metro cuadrado']);

        $this->getJson('/api/unit-types')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['name' => 'Caja'])
            ->assertJsonFragment(['name' => 'Metro cuadrado']);
    }
}
