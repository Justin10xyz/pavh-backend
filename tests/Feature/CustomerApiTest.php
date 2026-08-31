<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('sanctum.stateful')[0] ?? 'http://localhost:5173');

        $this->actingAs(User::factory()->create());
    }

    public function test_it_lists_customers_ordered_by_name(): void
    {
        Customer::create(['name' => 'Zaid']);
        Customer::create(['name' => 'Ana']);

        $this->getJson('/api/customers')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Ana')
            ->assertJsonPath('data.1.name', 'Zaid');
    }

    public function test_it_searches_customers_by_name_phone_or_email(): void
    {
        Customer::create(['name' => 'Juan Perez', 'phone' => '5551234567', 'email' => 'juan@example.com']);
        Customer::create(['name' => 'Maria Lopez', 'phone' => '5559876543', 'email' => 'maria@example.com']);

        $this->getJson('/api/customers?search=juan')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['name' => 'Juan Perez']);

        $this->getJson('/api/customers?search=5559876543')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['name' => 'Maria Lopez']);
    }

    public function test_it_creates_a_customer_with_only_name_required(): void
    {
        $this->postJson('/api/customers', ['name' => 'Cliente Rapido'])
            ->assertCreated()
            ->assertJsonFragment(['name' => 'Cliente Rapido', 'phone' => null, 'email' => null]);

        $this->assertDatabaseHas('customers', ['name' => 'Cliente Rapido']);
    }

    public function test_it_requires_name_to_create_a_customer(): void
    {
        $this->postJson('/api/customers', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_it_shows_a_customer(): void
    {
        $customer = Customer::create(['name' => 'Ver Cliente']);

        $this->getJson("/api/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonFragment(['name' => 'Ver Cliente']);
    }

    public function test_it_updates_a_customer(): void
    {
        $customer = Customer::create(['name' => 'Nombre Viejo']);

        $this->putJson("/api/customers/{$customer->id}", ['name' => 'Nombre Nuevo'])
            ->assertOk()
            ->assertJsonFragment(['name' => 'Nombre Nuevo']);

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'name' => 'Nombre Nuevo']);
    }
}
