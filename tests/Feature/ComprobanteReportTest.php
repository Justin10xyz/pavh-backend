<?php

namespace Tests\Feature;

use App\Models\Comprobante;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\QuoteStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
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

    /**
     * Filas del CSV ya parseadas, sin el BOM (se verifica aparte).
     *
     * @return list<list<string>>
     */
    private function csvRows(TestResponse $response): array
    {
        $body = substr($response->streamedContent(), 3);

        return array_map(
            fn (string $line) => str_getcsv($line, escape: ''),
            explode("\n", rtrim($body, "\n")),
        );
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

    public function test_csv_downloads_with_the_expected_filename_and_content_type(): void
    {
        $response = $this->get('/api/reports/comprobantes/csv?from=2026-10-01&to=2026-10-31')
            ->assertOk()
            ->assertDownload('Reporte-Comprobantes-2026-10-01-a-2026-10-31.csv');

        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
    }

    public function test_csv_starts_with_the_utf8_bom_and_the_header_row(): void
    {
        $response = $this->get('/api/reports/comprobantes/csv?from=2026-10-01&to=2026-10-31')->assertOk();

        $this->assertStringStartsWith("\xEF\xBB\xBFFolio,Fecha,Cliente,Subtotal,Total\n", $response->streamedContent());
    }

    public function test_csv_has_one_row_per_comprobante_in_the_same_order_as_the_json_items(): void
    {
        $customer = Customer::create(['name' => 'Juan Pérez']);

        $this->createComprobante('CF-0003', '2026-10-20', 300, 300, $customer);
        $this->createComprobante('CF-0001', '2026-10-01', 1000, 1000, $customer);
        $this->createComprobante('CF-0002', '2026-10-31', 200, 180, $customer);
        $this->createComprobante('CF-0004', '2026-11-01', 999, 999, $customer);

        $folios = $this->getJson('/api/reports/comprobantes?from=2026-10-01&to=2026-10-31')->json('items.*.folio');
        $rows = $this->csvRows($this->get('/api/reports/comprobantes/csv?from=2026-10-01&to=2026-10-31'));

        $this->assertCount(5, $rows);
        $this->assertSame($folios, array_column(array_slice($rows, 1, 3), 0));
        $this->assertSame(['CF-0001', '2026-10-01', 'Juan Pérez', '1000.00', '1000.00'], $rows[1]);
        $this->assertSame(['CF-0002', '2026-10-31', 'Juan Pérez', '200.00', '180.00'], $rows[3]);
    }

    public function test_csv_totals_row_matches_the_json_summary_without_floating_point_residue(): void
    {
        $this->createComprobante('CF-0001', '2026-10-01', 0.10, 0.10);
        $this->createComprobante('CF-0002', '2026-10-02', 0.20, 0.20);
        $this->createComprobante('CF-0003', '2026-10-03', 0.30, 0.30);

        $summary = $this->getJson('/api/reports/comprobantes?from=2026-10-01&to=2026-10-31')->json('summary');
        $rows = $this->csvRows($this->get('/api/reports/comprobantes/csv?from=2026-10-01&to=2026-10-31'));

        $this->assertSame(['TOTALES', '', '3 comprobantes', '0.60', '0.60'], end($rows));
        $this->assertSame([$summary['subtotal'], $summary['total']], array_slice(end($rows), 3));
    }

    public function test_csv_shows_sin_cliente_for_a_comprobante_without_customer(): void
    {
        $this->createComprobante('CF-0001', '2026-10-10', 100, 100);

        $rows = $this->csvRows($this->get('/api/reports/comprobantes/csv?from=2026-10-01&to=2026-10-31'));

        $this->assertSame('Sin cliente', $rows[1][2]);
    }

    public function test_csv_quotes_a_customer_name_with_a_comma(): void
    {
        $this->createComprobante('CF-0001', '2026-10-10', 100, 100, Customer::create(['name' => 'López, Juan']));

        $response = $this->get('/api/reports/comprobantes/csv?from=2026-10-01&to=2026-10-31');
        $rows = $this->csvRows($response);

        $this->assertStringContainsString('"López, Juan"', $response->streamedContent());
        $this->assertCount(5, $rows[1]);
        $this->assertSame('López, Juan', $rows[1][2]);
    }

    public function test_csv_for_a_month_without_comprobantes_has_only_header_and_zero_totals(): void
    {
        $this->createComprobante('CF-0001', '2026-09-10', 100, 100);

        $rows = $this->csvRows($this->get('/api/reports/comprobantes/csv?from=2026-10-01&to=2026-10-31')->assertOk());

        $this->assertSame([
            ['Folio', 'Fecha', 'Cliente', 'Subtotal', 'Total'],
            ['TOTALES', '', '0 comprobantes', '0.00', '0.00'],
        ], $rows);
    }

    public function test_csv_requires_from_and_to(): void
    {
        $this->getJson('/api/reports/comprobantes/csv')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['from', 'to']);

        $this->getJson('/api/reports/comprobantes/csv?from=2026-10-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');

        $this->getJson('/api/reports/comprobantes/csv?to=2026-10-31')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('from');
    }

    public function test_csv_to_cannot_be_before_from(): void
    {
        $this->getJson('/api/reports/comprobantes/csv?from=2026-10-31&to=2026-10-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');
    }

    public function test_csv_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/reports/comprobantes/csv?from=2026-10-01&to=2026-10-31')->assertUnauthorized();
    }
}
