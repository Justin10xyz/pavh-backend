<?php

namespace Tests\Feature;

use App\Models\Comprobante;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\QuoteStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComprobanteReportTest extends TestCase
{
    use RefreshDatabase;

    private QuoteStatus $status;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('sanctum.stateful')[0] ?? 'http://localhost:5173');

        $this->actingAs(User::factory()->create());

        $this->status = QuoteStatus::create(['name' => 'Convertida']);
    }

    /**
     * quote_id es UNIQUE: cada comprobante necesita su propia cotización.
     */
    private function createComprobante(string $folio, string $date, float $subtotal, float $total, ?Customer $customer = null): Comprobante
    {
        $quote = Quote::create([
            'folio' => 'COT-'.$folio,
            'quote_status_id' => $this->status->id,
            'customer_id' => $customer?->id,
            'subtotal' => $subtotal,
            'total' => $total,
        ]);

        return Comprobante::create([
            'folio' => $folio,
            'quote_id' => $quote->id,
            'customer_id' => $customer?->id,
            'date' => $date,
            'subtotal' => $subtotal,
            'total' => $total,
        ]);
    }

    public function test_it_lists_the_comprobantes_in_the_range_with_their_summary(): void
    {
        $customer = Customer::create(['name' => 'Juan Pérez']);

        $this->createComprobante('CF-0002', '2026-10-31', 200, 180, $customer);
        $this->createComprobante('CF-0001', '2026-10-01', 1000, 1000, $customer);

        $this->getJson('/api/reports/comprobantes?from=2026-10-01&to=2026-10-31')
            ->assertOk()
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.folio', 'CF-0001')
            ->assertJsonPath('items.0.date', '2026-10-01')
            ->assertJsonPath('items.0.customer', 'Juan Pérez')
            ->assertJsonPath('items.0.subtotal', '1000.00')
            ->assertJsonPath('items.0.total', '1000.00')
            ->assertJsonPath('items.1.folio', 'CF-0002')
            ->assertJsonPath('summary.count', 2)
            ->assertJsonPath('summary.subtotal', '1200.00')
            ->assertJsonPath('summary.total', '1180.00');
    }

    public function test_comprobantes_outside_the_range_are_excluded_from_items_and_summary(): void
    {
        $this->createComprobante('CF-0001', '2026-09-30', 500, 500);
        $this->createComprobante('CF-0002', '2026-10-15', 100, 100);
        $this->createComprobante('CF-0003', '2026-11-01', 700, 700);

        $response = $this->getJson('/api/reports/comprobantes?from=2026-10-01&to=2026-10-31')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.folio', 'CF-0002')
            ->assertJsonPath('summary.count', 1)
            ->assertJsonPath('summary.subtotal', '100.00')
            ->assertJsonPath('summary.total', '100.00');

        $this->assertNotContains('CF-0001', $response->json('items.*.folio'));
        $this->assertNotContains('CF-0003', $response->json('items.*.folio'));
    }

    public function test_a_comprobante_of_a_deleted_customer_keeps_the_customer_name(): void
    {
        $customer = Customer::create(['name' => 'Cliente dado de baja']);
        $this->createComprobante('CF-0001', '2026-10-10', 100, 100, $customer);
        $customer->delete();

        $this->getJson('/api/reports/comprobantes?from=2026-10-01&to=2026-10-31')
            ->assertOk()
            ->assertJsonPath('items.0.customer', 'Cliente dado de baja');
    }

    public function test_a_comprobante_without_customer_shows_null_customer(): void
    {
        $this->createComprobante('CF-0001', '2026-10-10', 100, 100);

        $this->getJson('/api/reports/comprobantes?from=2026-10-01&to=2026-10-31')
            ->assertOk()
            ->assertJsonPath('items.0.folio', 'CF-0001')
            ->assertJsonPath('items.0.customer', null);
    }

    public function test_a_month_without_comprobantes_returns_an_empty_report(): void
    {
        $this->createComprobante('CF-0001', '2026-09-10', 100, 100);

        $this->getJson('/api/reports/comprobantes?from=2026-10-01&to=2026-10-31')
            ->assertOk()
            ->assertExactJson([
                'items' => [],
                'summary' => [
                    'count' => 0,
                    'subtotal' => '0.00',
                    'total' => '0.00',
                ],
            ]);
    }

    public function test_from_and_to_are_required(): void
    {
        $this->getJson('/api/reports/comprobantes')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['from', 'to']);

        $this->getJson('/api/reports/comprobantes?from=2026-10-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');

        $this->getJson('/api/reports/comprobantes?to=2026-10-31')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('from');
    }

    public function test_to_cannot_be_before_from(): void
    {
        $this->getJson('/api/reports/comprobantes?from=2026-10-31&to=2026-10-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');
    }

    public function test_it_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/reports/comprobantes?from=2026-10-01&to=2026-10-31')->assertUnauthorized();
    }

    public function test_it_filters_by_the_comprobante_date_not_by_created_at(): void
    {
        $this->travelTo('2026-09-20 12:00:00');
        $comprobante = $this->createComprobante('CF-0001', '2026-09-20', 100, 100);
        $this->travelBack();

        $comprobante->update(['date' => '2026-10-05']);

        $this->getJson('/api/reports/comprobantes?from=2026-10-01&to=2026-10-31')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.folio', 'CF-0001')
            ->assertJsonPath('items.0.date', '2026-10-05');

        $this->getJson('/api/reports/comprobantes?from=2026-09-01&to=2026-09-30')
            ->assertOk()
            ->assertJsonCount(0, 'items')
            ->assertJsonPath('summary.count', 0);
    }

    public function test_the_summary_sums_without_floating_point_residue(): void
    {
        // Como float: 0.10 + 0.20 + 0.30 = 0.6000000000000001.
        $this->createComprobante('CF-0001', '2026-10-01', 0.10, 0.10);
        $this->createComprobante('CF-0002', '2026-10-02', 0.20, 0.20);
        $this->createComprobante('CF-0003', '2026-10-03', 0.30, 0.30);

        $response = $this->getJson('/api/reports/comprobantes?from=2026-10-01&to=2026-10-31')
            ->assertOk()
            ->assertJsonPath('summary.count', 3);

        $this->assertSame('0.60', $response->json('summary.subtotal'));
        $this->assertSame('0.60', $response->json('summary.total'));
    }
}
