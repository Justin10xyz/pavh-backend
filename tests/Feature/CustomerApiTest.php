<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Quote;
use App\Models\QuoteStatus;
use App\Models\Sale;
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

    public function test_it_soft_deletes_a_customer(): void
    {
        $customer = Customer::create(['name' => 'Cliente Borrado']);

        $this->deleteJson("/api/customers/{$customer->id}")->assertNoContent();

        $this->getJson('/api/customers')
            ->assertOk()
            ->assertJsonMissing(['id' => $customer->id]);

        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
    }

    public function test_it_deletes_a_customer_with_quotes_and_sales(): void
    {
        $customer = Customer::create(['name' => 'Cliente Con Historial']);
        $quote = $this->createQuoteFor($customer, 'COT-0001');
        $sale = Sale::create(['folio' => 'VEN-0001', 'customer_id' => $customer->id, 'subtotal' => 100, 'total' => 100]);

        $this->deleteJson("/api/customers/{$customer->id}")->assertNoContent();

        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
        $this->assertDatabaseHas('quotes', ['id' => $quote->id, 'customer_id' => $customer->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'customer_id' => $customer->id, 'deleted_at' => null]);
    }

    public function test_delete_summary_counts_quotes_and_sales_of_the_customer(): void
    {
        $customer = Customer::create(['name' => 'Cliente Frecuente']);
        $otherCustomer = Customer::create(['name' => 'Otro Cliente']);

        $this->createQuoteFor($customer, 'COT-0001');
        $this->createQuoteFor($customer, 'COT-0002');
        $this->createQuoteFor($otherCustomer, 'COT-0003');
        Sale::create(['folio' => 'VEN-0001', 'customer_id' => $customer->id, 'subtotal' => 100, 'total' => 100]);

        $this->getJson("/api/customers/{$customer->id}/delete-summary")
            ->assertOk()
            ->assertExactJson(['data' => ['quotes_count' => 2, 'sales_count' => 1]]);
    }

    public function test_delete_summary_returns_zero_counts_for_customer_without_history(): void
    {
        $customer = Customer::create(['name' => 'Cliente Nuevo']);

        $this->getJson("/api/customers/{$customer->id}/delete-summary")
            ->assertOk()
            ->assertExactJson(['data' => ['quotes_count' => 0, 'sales_count' => 0]]);
    }

    private function createQuoteFor(Customer $customer, string $folio): Quote
    {
        $status = QuoteStatus::firstOrCreate(['name' => 'Borrador']);

        return Quote::create([
            'folio' => $folio,
            'customer_id' => $customer->id,
            'quote_status_id' => $status->id,
            'subtotal' => 100,
            'total' => 100,
        ]);
    }
}
