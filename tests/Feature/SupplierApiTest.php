<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('sanctum.stateful')[0] ?? 'http://localhost:5173');

        $this->actingAs(User::factory()->create());
    }

    public function test_it_lists_suppliers(): void
    {
        Supplier::create(['name' => 'Acme']);
        Supplier::create(['name' => 'Globex']);

        $this->getJson('/api/suppliers')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['name' => 'Acme'])
            ->assertJsonFragment(['name' => 'Globex']);
    }
}
