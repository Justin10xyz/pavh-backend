<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Comprobante;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\QuoteStatus;
use App\Models\SimpleProduct;
use App\Models\Supplier;
use App\Models\UnitType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComprobanteApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ProductVariant $variant;

    private SimpleProduct $simpleProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('sanctum.stateful')[0] ?? 'http://localhost:5173');

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        foreach (['Borrador', 'Convertida', 'Cancelada'] as $name) {
            QuoteStatus::create(['name' => $name]);
        }

        $product = Product::create([
            'supplier_id' => Supplier::create(['name' => 'Interceramic'])->id,
            'category_id' => Category::create(['name' => 'Floor', 'code_prefix' => 'PIS'])->id,
            'unit_type_id' => UnitType::create(['name' => 'm2'])->id,
            'name' => 'Creato',
            'purchase_unit' => 'caja',
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $product->id,
            'code' => 'PIS-CREATO-TAUPE-60X120',
            'color' => 'Taupe',
            'size' => '60X120',
            'price_per_m2' => 250.00,
            'price_per_box' => 360.00,
            'm2_per_box' => 1.44,
            'stock_boxes' => 50,
        ]);

        $this->simpleProduct = SimpleProduct::create([
            'category_id' => Category::create(['name' => 'Materiales', 'code_prefix' => 'MAT', 'product_form_type' => 'simple'])->id,
            'name' => 'Pegazulejo gris 20kg',
            'price' => 189.50,
            'price_wholesale' => 170.00,
            'stock_quantity' => 50,
        ]);
    }

    /**
     * Cotización con 10 cajas de la variante (14.4 m² × 250 = 3,600.00) y 4
     * bultos de mayoreo (4 × 170 = 680.00): subtotal 4,280.00, total 4,180.00
     * con descuento de 100.
     */
    private function createQuote(bool $paid = true): int
    {
        $customer = Customer::create(['name' => 'Juan Pérez']);

        $quoteId = $this->postJson('/api/quotes', [
            'customer_id' => $customer->id,
            'discount' => 100,
            'items' => [
                ['product_variant_id' => $this->variant->id, 'boxes' => 10],
                ['simple_product_id' => $this->simpleProduct->id, 'quantity' => 4, 'price_type' => 'wholesale'],
            ],
        ])->assertCreated()->json('data.id');

        if ($paid) {
            $this->patchJson("/api/quotes/{$quoteId}/mark-paid")->assertOk();
        }

        return $quoteId;
    }

    public function test_to_comprobante_prefills_the_paid_quote_without_mutating(): void
    {
        $quoteId = $this->createQuote();

        $this->getJson("/api/quotes/{$quoteId}/to-comprobante")
            ->assertOk()
            ->assertJsonPath('data.quote_id', $quoteId)
            ->assertJsonPath('data.customer.name', 'Juan Pérez')
            ->assertJsonPath('data.date', now()->toDateString())
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.subtotal', '4280.00')
            ->assertJsonPath('data.total', '4180.00')
            ->assertJsonPath('data.total_in_words', 'cuatro mil ciento ochenta pesos 00/100 M.N.');

        $this->assertDatabaseCount('comprobantes', 0);
    }

    public function test_store_copies_lines_and_amounts_as_is_and_generates_folio(): void
    {
        $quoteId = $this->createQuote();

        // Cambiar precios después de pagar no afecta: se copia lo congelado.
        $this->variant->update(['price_per_m2' => 999.00]);
        $this->simpleProduct->update(['price_wholesale' => 1.00]);

        $response = $this->postJson('/api/comprobantes', [
            'quote_id' => $quoteId,
            'date' => '2026-10-15',
            // Ignorados: no tienen regla en el Form Request.
            'folio' => 'HACK-1',
            'total' => 1,
            'items' => [],
        ])
            ->assertCreated()
            ->assertJsonPath('data.folio', 'CF-0001')
            ->assertJsonPath('data.quote.id', $quoteId)
            ->assertJsonPath('data.customer.name', 'Juan Pérez')
            ->assertJsonPath('data.date', '2026-10-15')
            ->assertJsonPath('data.subtotal', '4280.00')
            ->assertJsonPath('data.total', '4180.00')
            ->assertJsonPath('data.total_in_words', 'cuatro mil ciento ochenta pesos 00/100 M.N.')
            ->assertJsonPath('data.created_by', $this->user->id)
            ->assertJsonCount(2, 'data.items');

        $comprobante = Comprobante::findOrFail($response->json('data.id'));
        $quoteItems = $comprobante->quote->items()->orderBy('id')->get();
        $comprobanteItems = $comprobante->items()->orderBy('id')->get();

        $fields = ['product_variant_id', 'simple_product_id', 'boxes', 'quantity', 'unit_price', 'price_type', 'line_total'];

        foreach ($quoteItems as $index => $quoteItem) {
            $this->assertSame($quoteItem->only($fields), $comprobanteItems[$index]->only($fields));
        }

        $this->assertSame(10, $comprobanteItems[0]->boxes);
        $this->assertSame('14.400', $comprobanteItems[0]->quantity);
        $this->assertSame('3600.00', $comprobanteItems[0]->line_total);
        $this->assertSame('wholesale', $comprobanteItems[1]->price_type);
        $this->assertSame('170.00', $comprobanteItems[1]->unit_price);
    }

    public function test_store_defaults_date_to_today(): void
    {
        $quoteId = $this->createQuote();

        $this->postJson('/api/comprobantes', ['quote_id' => $quoteId])
            ->assertCreated()
            ->assertJsonPath('data.date', now()->toDateString());
    }

    public function test_it_rejects_converting_an_unpaid_quote(): void
    {
        $quoteId = $this->createQuote(paid: false);
        $message = 'Esta cotización no ha sido pagada y no se puede convertir a comprobante.';

        $this->getJson("/api/quotes/{$quoteId}/to-comprobante")
            ->assertUnprocessable()
            ->assertJsonPath('message', $message);

        $this->postJson('/api/comprobantes', ['quote_id' => $quoteId])
            ->assertUnprocessable()
            ->assertJsonPath('message', $message);

        $this->assertDatabaseCount('comprobantes', 0);
    }

    public function test_it_rejects_a_second_conversion_of_the_same_quote(): void
    {
        $quoteId = $this->createQuote();
        $message = 'Esta cotización ya tiene un comprobante.';

        $this->postJson('/api/comprobantes', ['quote_id' => $quoteId])->assertCreated();

        $this->getJson("/api/quotes/{$quoteId}/to-comprobante")
            ->assertUnprocessable()
            ->assertJsonPath('message', $message);

        $this->postJson('/api/comprobantes', ['quote_id' => $quoteId])
            ->assertUnprocessable()
            ->assertJsonPath('message', $message);

        // Uno dado de baja también cuenta (el índice UNIQUE lo incluye).
        Comprobante::where('quote_id', $quoteId)->firstOrFail()->delete();

        $this->postJson('/api/comprobantes', ['quote_id' => $quoteId])
            ->assertUnprocessable()
            ->assertJsonPath('message', $message);

        $this->assertSame(1, Comprobante::withTrashed()->count());
    }

    public function test_update_date_changes_only_the_date_without_restriction(): void
    {
        $quoteId = $this->createQuote();
        $comprobanteId = $this->postJson('/api/comprobantes', ['quote_id' => $quoteId, 'date' => '2026-10-15'])
            ->assertCreated()
            ->json('data.id');

        $this->patchJson("/api/comprobantes/{$comprobanteId}", ['date' => '2026-09-01', 'total' => 1, 'folio' => 'HACK-1'])
            ->assertOk()
            ->assertJsonPath('data.date', '2026-09-01')
            ->assertJsonPath('data.total', '4180.00')
            ->assertJsonPath('data.folio', 'CF-0001');

        // Sin límite: se puede volver a cambiar.
        $this->patchJson("/api/comprobantes/{$comprobanteId}", ['date' => '2027-01-31'])
            ->assertOk()
            ->assertJsonPath('data.date', '2027-01-31');

        $this->assertSame('2027-01-31', Comprobante::findOrFail($comprobanteId)->date->toDateString());

        $this->patchJson("/api/comprobantes/{$comprobanteId}", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date']);
    }
}
