<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quote;
use App\Models\QuoteStatus;
use App\Models\SimpleProduct;
use App\Models\Supplier;
use App\Models\UnitType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Flujo de punta a punta de una cotización mixta (variante + producto simple):
 * cotizar → consultar → editar → convertir → vender → PDF. Complementa los
 * tests por paso de QuoteApiTest/SaleApiTest.
 */
class MixedQuoteToSaleFlowTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariant $variant;

    private SimpleProduct $simpleProduct;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('sanctum.stateful')[0] ?? 'http://localhost:5173');

        $this->actingAs(User::factory()->create());

        foreach (['Borrador', 'Convertida', 'Cancelada'] as $name) {
            QuoteStatus::create(['name' => $name]);
        }

        $product = Product::create([
            'supplier_id' => Supplier::create(['name' => 'Interceramic'])->id,
            'category_id' => Category::create(['name' => 'Pisos', 'code_prefix' => 'PIS'])->id,
            'unit_type_id' => UnitType::create(['name' => 'm2'])->id,
            'name' => 'Creato',
            'purchase_unit' => 'caja',
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $product->id,
            'code' => 'PIS-CREATO-TAU-60X120',
            'color' => 'Taupe',
            'size' => '60x120',
            'price_per_m2' => 100.00,
            'price_per_box' => 144.00,
            'm2_per_box' => 1.44,
            'stock_boxes' => 10,
        ]);

        $simpleCategory = Category::create(['name' => 'Materiales', 'code_prefix' => 'MAT', 'product_form_type' => 'simple']);

        $this->simpleProduct = SimpleProduct::factory()->create([
            'category_id' => $simpleCategory->id,
            'name' => 'Pegazulejo gris 20kg',
            'price' => 189.50,
            'stock_quantity' => 20,
        ]);

        $this->customer = Customer::create(['name' => 'Juan Perez']);
    }

    /**
     * Crea la cotización mixta (paso 4) y devuelve su id. Los unit_price del
     * payload son basura a propósito: el backend debe ignorarlos.
     */
    private function createMixedQuote(int $simpleQuantity = 3): int
    {
        $response = $this->postJson('/api/quotes', [
            'customer_id' => $this->customer->id,
            'items' => [
                ['product_variant_id' => $this->variant->id, 'quantity' => 5, 'unit_price' => 1],
                ['simple_product_id' => $this->simpleProduct->id, 'quantity' => $simpleQuantity, 'unit_price' => 1],
            ],
        ])->assertCreated();

        return $response->json('data.id');
    }

    /**
     * Arma el payload de POST /sales a partir de lo que devolvió convert,
     * igual que lo hace el frontend.
     */
    private function salePayloadFromConvert(array $convertData): array
    {
        return [
            'quote_id' => $convertData['quote_id'],
            'customer_id' => $convertData['customer_id'],
            'items' => array_map(fn ($item) => [
                'product_variant_id' => $item['product_variant_id'],
                'simple_product_id' => $item['simple_product_id'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
            ], $convertData['items']),
        ];
    }

    public function test_full_mixed_quote_to_sale_flow(): void
    {
        // 4. Crear cotización: precios resueltos server-side.
        //    5 m² × 100.00 = 500.00 ; 3 × 189.50 = 568.50 ; total 1068.50
        $quoteId = $this->createMixedQuote(simpleQuantity: 3);

        $this->assertDatabaseHas('quotes', ['id' => $quoteId, 'subtotal' => 1068.50, 'total' => 1068.50]);

        // 5. Detalle: cada línea expone su relación correspondiente.
        $this->getJson("/api/quotes/{$quoteId}")
            ->assertOk()
            ->assertJsonPath('data.items.0.unit_price', '100.00')
            ->assertJsonPath('data.items.0.line_total', '500.00')
            ->assertJsonPath('data.items.0.product_variant.id', $this->variant->id)
            ->assertJsonPath('data.items.0.product_variant.product.name', 'Creato')
            ->assertJsonPath('data.items.0.simple_product_id', null)
            ->assertJsonPath('data.items.1.unit_price', '189.50')
            ->assertJsonPath('data.items.1.line_total', '568.50')
            ->assertJsonPath('data.items.1.product_variant', null)
            ->assertJsonPath('data.items.1.simple_product.id', $this->simpleProduct->id)
            ->assertJsonPath('data.items.1.simple_product.name', 'Pegazulejo gris 20kg')
            ->assertJsonPath('data.items.1.simple_product.price', '189.50')
            ->assertJsonPath('data.subtotal', '1068.50')
            ->assertJsonPath('data.total', '1068.50');

        // 6. Editar: la línea simple pasa de 3 a 4 → 500.00 + 758.00 = 1258.00
        $this->putJson("/api/quotes/{$quoteId}", [
            'items' => [
                ['product_variant_id' => $this->variant->id, 'quantity' => 5],
                ['simple_product_id' => $this->simpleProduct->id, 'quantity' => 4],
            ],
        ])->assertOk()
            ->assertJsonPath('data.items.1.quantity', '4.00')
            ->assertJsonPath('data.items.1.line_total', '758.00')
            ->assertJsonPath('data.subtotal', '1258.00')
            ->assertJsonPath('data.total', '1258.00');

        // 7. Convert: precio de HOY para ambos tipos de línea, no el congelado.
        $this->variant->update(['price_per_m2' => 120.00]);
        $this->simpleProduct->update(['price' => 200.00]);

        $convertResponse = $this->getJson("/api/quotes/{$quoteId}/convert")
            ->assertOk()
            ->assertJsonPath('data.quote_id', $quoteId)
            ->assertJsonPath('data.customer_id', $this->customer->id)
            ->assertJsonPath('data.items.0.product_variant_id', $this->variant->id)
            ->assertJsonPath('data.items.0.unit_price', '120.00')
            ->assertJsonPath('data.items.1.simple_product_id', $this->simpleProduct->id)
            ->assertJsonPath('data.items.1.unit_price', '200.00');

        // 8. Venta desde la cotización, con las líneas tal cual las devolvió convert.
        //    5 × 120.00 + 4 × 200.00 = 600.00 + 800.00 = 1400.00
        $saleResponse = $this->postJson('/api/sales', $this->salePayloadFromConvert($convertResponse->json('data')))
            ->assertCreated()
            ->assertJsonPath('data.quote_id', $quoteId)
            ->assertJsonPath('data.subtotal', '1400.00')
            ->assertJsonPath('data.total', '1400.00');

        // Variante: ceil(5 m² / 1.44) = 4 cajas → 10 - 4 = 6
        $this->assertDatabaseHas('product_variants', ['id' => $this->variant->id, 'stock_boxes' => 6]);
        // Producto simple: 1:1, sin conversión → 20 - 4 = 16
        $this->assertDatabaseHas('simple_products', ['id' => $this->simpleProduct->id, 'stock_quantity' => 16]);

        $this->assertSame('Convertida', Quote::find($quoteId)->quoteStatus->name);

        // 9. PDF de la venta con línea mixta.
        $this->get("/api/sales/{$saleResponse->json('data.id')}/pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_mixed_conversion_with_insufficient_simple_product_stock_changes_nothing(): void
    {
        $quoteId = $this->createMixedQuote(simpleQuantity: 4);

        // El stock baja después de cotizar (ej. otra venta de mostrador).
        $this->simpleProduct->update(['stock_quantity' => 3]);

        $convertResponse = $this->getJson("/api/quotes/{$quoteId}/convert")->assertOk();

        $this->postJson('/api/sales', $this->salePayloadFromConvert($convertResponse->json('data')))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Stock insuficiente para Pegazulejo gris 20kg. Disponible: 3 unidades, se requieren 4.');

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_items', 0);

        // Ni la variante (que sí tenía stock suficiente) ni el producto simple se tocaron.
        $this->assertDatabaseHas('product_variants', ['id' => $this->variant->id, 'stock_boxes' => 10]);
        $this->assertDatabaseHas('simple_products', ['id' => $this->simpleProduct->id, 'stock_quantity' => 3]);

        $this->assertSame('Borrador', Quote::find($quoteId)->quoteStatus->name);
    }
}
