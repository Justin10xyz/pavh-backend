<?php

namespace Tests\Feature;

use App\Models\Category;
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

/**
 * Flujo de punta a punta de una cotización mixta (variante + producto simple):
 * cotizar → consultar → editar. Complementa los tests por paso de QuoteApiTest.
 */
class MixedQuoteFlowTest extends TestCase
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

        foreach (['Borrador', 'Pagada', 'Cancelada'] as $name) {
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
                ['product_variant_id' => $this->variant->id, 'boxes' => 4, 'unit_price' => 1],
                ['simple_product_id' => $this->simpleProduct->id, 'quantity' => $simpleQuantity, 'unit_price' => 1],
            ],
        ])->assertCreated();

        return $response->json('data.id');
    }

    public function test_full_mixed_quote_flow(): void
    {
        // 4. Crear cotización: precios resueltos server-side.
        //    4 cajas × 1.44 = 5.76 m² × 100.00 = 576.00 ; 3 × 189.50 = 568.50 ; total 1144.50
        $quoteId = $this->createMixedQuote(simpleQuantity: 3);

        $this->assertDatabaseHas('quotes', ['id' => $quoteId, 'subtotal' => 1144.50, 'total' => 1144.50]);

        // 5. Detalle: cada línea expone su relación correspondiente.
        $this->getJson("/api/quotes/{$quoteId}")
            ->assertOk()
            ->assertJsonPath('data.items.0.unit_price', '100.00')
            ->assertJsonPath('data.items.0.boxes', 4)
            ->assertJsonPath('data.items.0.quantity', '5.760')
            ->assertJsonPath('data.items.0.line_total', '576.00')
            ->assertJsonPath('data.items.0.product_variant.id', $this->variant->id)
            ->assertJsonPath('data.items.0.product_variant.product.name', 'Creato')
            ->assertJsonPath('data.items.0.simple_product_id', null)
            ->assertJsonPath('data.items.1.unit_price', '189.50')
            ->assertJsonPath('data.items.1.line_total', '568.50')
            ->assertJsonPath('data.items.1.product_variant', null)
            ->assertJsonPath('data.items.1.simple_product.id', $this->simpleProduct->id)
            ->assertJsonPath('data.items.1.simple_product.name', 'Pegazulejo gris 20kg')
            ->assertJsonPath('data.items.1.simple_product.price', '189.50')
            ->assertJsonPath('data.subtotal', '1144.50')
            ->assertJsonPath('data.total', '1144.50');

        // 6. Editar: la línea simple pasa de 3 a 4 → 576.00 + 758.00 = 1334.00
        $this->putJson("/api/quotes/{$quoteId}", [
            'items' => [
                ['product_variant_id' => $this->variant->id, 'boxes' => 4],
                ['simple_product_id' => $this->simpleProduct->id, 'quantity' => 4],
            ],
        ])->assertOk()
            ->assertJsonPath('data.items.1.quantity', '4.000')
            ->assertJsonPath('data.items.1.line_total', '758.00')
            ->assertJsonPath('data.subtotal', '1334.00')
            ->assertJsonPath('data.total', '1334.00');
    }
}
