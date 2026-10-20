<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quote;
use App\Models\QuoteStatus;
use App\Models\SimpleProduct;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\UnitType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuotePaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Product $product;

    private Category $simpleCategory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('sanctum.stateful')[0] ?? 'http://localhost:5173');

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        foreach (['Borrador', 'Convertida', 'Cancelada'] as $name) {
            QuoteStatus::create(['name' => $name]);
        }

        $this->product = Product::create([
            'supplier_id' => Supplier::create(['name' => 'Interceramic'])->id,
            'category_id' => Category::create(['name' => 'Floor', 'code_prefix' => 'PIS'])->id,
            'unit_type_id' => UnitType::create(['name' => 'm2'])->id,
            'name' => 'Creato',
            'purchase_unit' => 'caja',
        ]);

        $this->simpleCategory = Category::create(['name' => 'Materiales', 'code_prefix' => 'MAT', 'product_form_type' => 'simple']);
    }

    // m2_per_box=1.0: N cajas = N m², así los montos se leen directo.
    private function createVariant(string $color, ?int $stockBoxes, float $pricePerM2 = 100.00): ProductVariant
    {
        return ProductVariant::create([
            'product_id' => $this->product->id,
            'code' => "PIS-CREATO-{$color}-60X120",
            'color' => $color,
            'size' => '60X120',
            'price_per_m2' => $pricePerM2,
            'price_per_box' => $pricePerM2,
            'm2_per_box' => 1.0,
            'stock_boxes' => $stockBoxes,
        ]);
    }

    private function createSimpleProduct(?int $stockQuantity, float $price = 50.00): SimpleProduct
    {
        return SimpleProduct::create([
            'category_id' => $this->simpleCategory->id,
            'name' => 'Pegazulejo gris 20kg',
            'price' => $price,
            'stock_quantity' => $stockQuantity,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $extra
     */
    private function createQuote(array $items, array $extra = []): int
    {
        return $this->postJson('/api/quotes', [...$extra, 'items' => $items])
            ->assertCreated()
            ->json('data.id');
    }

    public function test_mark_paid_deducts_stock_aggregated_by_product(): void
    {
        $variant = $this->createVariant('Taupe', 10);
        $simpleProduct = $this->createSimpleProduct(20);

        // Líneas duplicadas del mismo producto: se descuenta la suma.
        $quoteId = $this->createQuote([
            ['product_variant_id' => $variant->id, 'boxes' => 3],
            ['simple_product_id' => $simpleProduct->id, 'quantity' => 5],
            ['product_variant_id' => $variant->id, 'boxes' => 4],
            ['simple_product_id' => $simpleProduct->id, 'quantity' => 2],
        ]);

        $this->patchJson("/api/quotes/{$quoteId}/mark-paid")
            ->assertOk()
            ->assertJsonPath('data.status', 'Borrador')
            ->assertJsonPath('data.paid_by', $this->user->id)
            ->assertJsonPath('data.paid_at', fn ($paidAt) => $paidAt !== null);

        $this->assertSame(3, $variant->fresh()->stock_boxes);
        $this->assertSame(13, $simpleProduct->fresh()->stock_quantity);

        $quote = Quote::find($quoteId);
        $this->assertNotNull($quote->paid_at);
        $this->assertSame($this->user->id, $quote->paid_by);

        $this->assertDatabaseHas('stock_movements', [
            'product_variant_id' => $variant->id,
            'type' => 'subtract',
            'quantity' => 7,
            'source' => 'quote_payment',
            'quote_id' => $quoteId,
            'sale_id' => null,
            'resulting_stock' => 3,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'simple_product_id' => $simpleProduct->id,
            'type' => 'subtract',
            'quantity' => 7,
            'source' => 'quote_payment',
            'quote_id' => $quoteId,
            'resulting_stock' => 13,
        ]);
        $this->assertSame(2, StockMovement::where('source', 'quote_payment')->count());
    }

    public function test_mark_paid_ignores_products_without_stock_tracking(): void
    {
        $untrackedVariant = $this->createVariant('Gris', null);
        $untrackedSimple = $this->createSimpleProduct(null);

        $quoteId = $this->createQuote([
            ['product_variant_id' => $untrackedVariant->id, 'boxes' => 500],
            ['simple_product_id' => $untrackedSimple->id, 'quantity' => 500],
        ]);

        $this->patchJson("/api/quotes/{$quoteId}/mark-paid")->assertOk();

        $this->assertNull($untrackedVariant->fresh()->stock_boxes);
        $this->assertNull($untrackedSimple->fresh()->stock_quantity);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_mark_paid_rejects_insufficient_stock_without_mutating_anything(): void
    {
        // La variante alcanza; el producto simple no. No se descuenta ninguno.
        $variant = $this->createVariant('Taupe', 10);
        $simpleProduct = $this->createSimpleProduct(4);

        $quoteId = $this->createQuote([
            ['product_variant_id' => $variant->id, 'boxes' => 3],
            ['simple_product_id' => $simpleProduct->id, 'quantity' => 3],
            ['simple_product_id' => $simpleProduct->id, 'quantity' => 2],
        ]);

        $this->patchJson("/api/quotes/{$quoteId}/mark-paid")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Stock insuficiente para Pegazulejo gris 20kg. Disponible: 4 unidades, se requieren 5.');

        $this->assertSame(10, $variant->fresh()->stock_boxes);
        $this->assertSame(4, $simpleProduct->fresh()->stock_quantity);
        $this->assertSame(0, StockMovement::count());
        $this->assertNull(Quote::find($quoteId)->paid_at);
    }

    public function test_mark_paid_rejects_insufficient_variant_stock(): void
    {
        $variant = $this->createVariant('Taupe', 5);

        $quoteId = $this->createQuote([
            ['product_variant_id' => $variant->id, 'boxes' => 4],
            ['product_variant_id' => $variant->id, 'boxes' => 2],
        ]);

        $this->patchJson("/api/quotes/{$quoteId}/mark-paid")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Stock insuficiente para PIS-CREATO-Taupe-60X120. Disponible: 5 cajas, se requieren 6.');

        $this->assertSame(5, $variant->fresh()->stock_boxes);
        $this->assertNull(Quote::find($quoteId)->paid_at);
    }

    public function test_mark_paid_rejects_paying_twice(): void
    {
        $variant = $this->createVariant('Taupe', 10);
        $quoteId = $this->createQuote([['product_variant_id' => $variant->id, 'boxes' => 3]]);

        $this->patchJson("/api/quotes/{$quoteId}/mark-paid")->assertOk();

        $this->patchJson("/api/quotes/{$quoteId}/mark-paid")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Esta cotización ya fue marcada como pagada.');

        $this->assertSame(7, $variant->fresh()->stock_boxes);
        $this->assertSame(1, StockMovement::count());
    }

    public function test_mark_paid_rejects_a_quote_that_is_not_in_draft(): void
    {
        $variant = $this->createVariant('Taupe', 10);
        $quoteId = $this->createQuote([['product_variant_id' => $variant->id, 'boxes' => 3]]);

        $this->patchJson("/api/quotes/{$quoteId}/cancel")->assertOk();

        $this->patchJson("/api/quotes/{$quoteId}/mark-paid")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Esta cotización está cancelada y no se puede marcar como pagada.');

        $this->assertSame(10, $variant->fresh()->stock_boxes);
        $this->assertNull(Quote::find($quoteId)->paid_at);
    }

    public function test_mark_paid_rejects_a_soft_deleted_product(): void
    {
        $variant = $this->createVariant('Taupe', 10);
        $quoteId = $this->createQuote([['product_variant_id' => $variant->id, 'boxes' => 3]]);

        $variant->delete();

        $this->patchJson("/api/quotes/{$quoteId}/mark-paid")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'No se puede marcar como pagada: la variante PIS-CREATO-Taupe-60X120 ya no está disponible.');

        $this->assertSame(10, ProductVariant::withTrashed()->find($variant->id)->stock_boxes);
    }

    public function test_a_paid_quote_cannot_be_edited_cancelled_or_converted(): void
    {
        // Su stock ya se descontó: editar, cancelar o convertir a venta lo
        // dejaría descuadrado (o lo descontaría dos veces).
        $variant = $this->createVariant('Taupe', 10);
        $items = [['product_variant_id' => $variant->id, 'boxes' => 3]];
        $quoteId = $this->createQuote($items);

        $this->patchJson("/api/quotes/{$quoteId}/mark-paid")->assertOk();

        $this->putJson("/api/quotes/{$quoteId}", ['items' => $items])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Esta cotización ya fue pagada y no se puede editar.');

        $this->patchJson("/api/quotes/{$quoteId}/cancel")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Esta cotización ya fue pagada y no se puede cancelar.');

        $this->getJson("/api/quotes/{$quoteId}/convert")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Esta cotización ya fue pagada y no se puede convertir a venta.');

        $this->postJson('/api/sales', [
            'quote_id' => $quoteId,
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 3, 'unit_price' => 100]],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Esta cotización ya fue pagada y no se puede convertir a venta.');

        $this->assertSame(7, $variant->fresh()->stock_boxes);
    }

    public function test_total_is_subtotal_minus_discount_on_create_and_update(): void
    {
        $variant = $this->createVariant('Taupe', null, 100.00);

        // 5 cajas × 1 m² × $100 = $500 - $80 = $420
        $quoteId = $this->postJson('/api/quotes', [
            'discount' => 80,
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 5]],
        ])
            ->assertCreated()
            ->assertJsonPath('data.subtotal', '500.00')
            ->assertJsonPath('data.discount', '80.00')
            ->assertJsonPath('data.total', '420.00')
            ->json('data.id');

        // Sin discount en el payload se conserva el guardado: $300 - $80.
        $this->putJson("/api/quotes/{$quoteId}", [
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 3]],
        ])
            ->assertOk()
            ->assertJsonPath('data.subtotal', '300.00')
            ->assertJsonPath('data.total', '220.00');

        // discount null = no aplica: total = subtotal.
        $this->putJson("/api/quotes/{$quoteId}", [
            'discount' => null,
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 3]],
        ])
            ->assertOk()
            ->assertJsonPath('data.discount', null)
            ->assertJsonPath('data.total', '300.00');

        // Descuento igual al subtotal: total 0 es válido.
        $this->putJson("/api/quotes/{$quoteId}", [
            'discount' => 300,
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 3]],
        ])
            ->assertOk()
            ->assertJsonPath('data.total', '0.00');
    }

    public function test_total_includes_shipping_cost_on_create_and_update(): void
    {
        $variant = $this->createVariant('Taupe', null, 100.00);

        // 5 cajas × 1 m² × $100 = $500 - $80 + $120.50 = $540.50
        $quoteId = $this->postJson('/api/quotes', [
            'discount' => 80,
            'shipping_cost' => 120.50,
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 5]],
        ])
            ->assertCreated()
            ->assertJsonPath('data.subtotal', '500.00')
            ->assertJsonPath('data.total', '540.50')
            ->assertJsonPath('data.balance', '540.50')
            ->json('data.id');

        // Sin shipping_cost en el payload se conserva el guardado: $300 - $80 + $120.50.
        $this->putJson("/api/quotes/{$quoteId}", [
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 3]],
        ])
            ->assertOk()
            ->assertJsonPath('data.total', '340.50');

        // shipping_cost null = no aplica: total = subtotal - descuento.
        $this->putJson("/api/quotes/{$quoteId}", [
            'shipping_cost' => null,
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 3]],
        ])
            ->assertOk()
            ->assertJsonPath('data.shipping_cost', null)
            ->assertJsonPath('data.total', '220.00');
    }

    public function test_shipping_cost_does_not_cover_a_discount_greater_than_the_subtotal(): void
    {
        $variant = $this->createVariant('Taupe', null, 100.00);

        // subtotal 500 - 600 + 200 = 100 >= 0, pero el descuento sigue siendo
        // mayor que el subtotal.
        $this->postJson('/api/quotes', [
            'discount' => 600,
            'shipping_cost' => 200,
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 5]],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'El descuento ($600.00) no puede ser mayor que el subtotal ($500.00).');

        $this->assertSame(0, Quote::count());

        $quoteId = $this->createQuote(
            [['product_variant_id' => $variant->id, 'boxes' => 5]],
            ['discount' => 400, 'shipping_cost' => 500],
        );

        $this->putJson("/api/quotes/{$quoteId}", [
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 3]],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'El descuento ($400.00) no puede ser mayor que el subtotal ($300.00).');

        $this->assertSame('600.00', Quote::find($quoteId)->total);
    }

    public function test_migration_moves_shipping_into_total_without_changing_balance(): void
    {
        $variant = $this->createVariant('Taupe', null, 100.00);

        $withShippingId = $this->createQuote(
            [['product_variant_id' => $variant->id, 'boxes' => 10]],
            ['discount' => 100, 'shipping_cost' => 150, 'down_payment' => 300],
        );
        $withoutShippingId = $this->createQuote(
            [['product_variant_id' => $variant->id, 'boxes' => 10]],
            ['discount' => 100],
        );

        // Estado previo: total sin envío y saldo viejo (total + envío - anticipo) = 750.
        $migration = require database_path('migrations/2026_10_20_000001_include_shipping_cost_in_quotes_total.php');
        $migration->down();

        $this->assertSame('900.00', Quote::find($withShippingId)->total);
        $this->assertSame('900.00', Quote::find($withoutShippingId)->total);

        $migration->up();

        $this->assertSame('1050.00', Quote::find($withShippingId)->total);
        $this->assertSame(750.00, Quote::find($withShippingId)->balance());
        $this->assertSame('900.00', Quote::find($withoutShippingId)->total);
    }

    public function test_it_rejects_a_discount_greater_than_the_subtotal(): void
    {
        $variant = $this->createVariant('Taupe', null, 100.00);

        $this->postJson('/api/quotes', [
            'discount' => 500.01,
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 5]],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'El descuento ($500.01) no puede ser mayor que el subtotal ($500.00).');

        $this->assertSame(0, Quote::count());

        $quoteId = $this->createQuote(
            [['product_variant_id' => $variant->id, 'boxes' => 5]],
            ['discount' => 400],
        );

        // Bajar las líneas sin tocar el descuento guardado también lo rechaza,
        // sin reemplazar las líneas.
        $this->putJson("/api/quotes/{$quoteId}", [
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 3]],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'El descuento ($400.00) no puede ser mayor que el subtotal ($300.00).');

        $quote = Quote::with('items')->find($quoteId);
        $this->assertSame('100.00', $quote->total);
        $this->assertSame(5, $quote->items->first()->boxes);
    }

    public function test_balance_is_exposed_in_the_resource(): void
    {
        $variant = $this->createVariant('Taupe', null, 100.00);

        // $1,000 - $100 de descuento + $150 de envío - $300 de anticipo = $750
        $this->postJson('/api/quotes', [
            'discount' => 100,
            'shipping_cost' => 150,
            'down_payment' => 300,
            'payment_terms' => 'credit',
            'credit_due_date' => '2026-11-30',
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 10]],
        ])
            ->assertCreated()
            ->assertJsonPath('data.total', '1050.00')
            ->assertJsonPath('data.shipping_cost', '150.00')
            ->assertJsonPath('data.down_payment', '300.00')
            ->assertJsonPath('data.balance', '750.00')
            ->assertJsonPath('data.payment_terms', 'credit')
            ->assertJsonPath('data.credit_due_date', '2026-11-30')
            ->assertJsonPath('data.paid_at', null);

        // Sin campos de pago: payment_terms queda en su default y el saldo es el total.
        $this->postJson('/api/quotes', [
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 2]],
        ])
            ->assertCreated()
            ->assertJsonPath('data.payment_terms', 'cash')
            ->assertJsonPath('data.credit_due_date', null)
            ->assertJsonPath('data.balance', '200.00');
    }

    public function test_balance_is_total_minus_down_payment_without_adding_shipping_again(): void
    {
        $cases = [
            // [total (ya con envío), shipping_cost, down_payment, balance esperado]
            [1000, null, null, 1000.00],
            [1250.50, 250.50, null, 1250.50],
            [1000, null, 400, 600.00],
            [1250.50, 250.50, 400, 850.50],
            [0, null, null, 0.00],
            [100, 0.01, 50, 50.00],
        ];

        foreach ($cases as [$total, $shippingCost, $downPayment, $expected]) {
            $quote = new Quote([
                'total' => $total,
                'shipping_cost' => $shippingCost,
                'down_payment' => $downPayment,
            ]);

            $this->assertSame($expected, $quote->balance(), "total={$total}, envío={$shippingCost}, anticipo={$downPayment}");
        }
    }

    public function test_a_paid_quote_cannot_be_deleted(): void
    {
        $variant = $this->createVariant('Taupe', 10);
        $quoteId = $this->createQuote([['product_variant_id' => $variant->id, 'boxes' => 2]]);

        $this->patchJson("/api/quotes/{$quoteId}/mark-paid")->assertOk();

        $this->deleteJson("/api/quotes/{$quoteId}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Esta cotización ya fue pagada y no se puede eliminar.');

        $this->assertNotSoftDeleted('quotes', ['id' => $quoteId]);
        $this->assertDatabaseCount('quote_items', 1);
    }
}
