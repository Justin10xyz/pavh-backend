<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\QuoteStatus;
use App\Models\Supplier;
use App\Models\UnitType;
use App\Models\User;
use App\Services\QuotePdfGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('sanctum.stateful')[0] ?? 'http://localhost:5173');

        $this->actingAs(User::factory()->create());

        QuoteStatus::create(['name' => 'Borrador']);
    }

    private function createProduct(string $name = 'Creato'): Product
    {
        return Product::create([
            'supplier_id' => Supplier::firstOrCreate(['name' => 'Interceramic'])->id,
            'category_id' => Category::firstOrCreate(['name' => 'Floor', 'code_prefix' => 'PIS'])->id,
            'unit_type_id' => UnitType::firstOrCreate(['name' => 'm2'])->id,
            'name' => $name,
            'purchase_unit' => 'caja',
        ]);
    }

    private function createVariant(Product $product, string $color, string $size = '60X120'): ProductVariant
    {
        return ProductVariant::create([
            'product_id' => $product->id,
            'code' => strtoupper("PIS-{$product->name}-{$color}-{$size}"),
            'color' => $color,
            'size' => $size,
            'price_per_m2' => 100.00,
            'price_per_box' => 144.00,
            'm2_per_box' => 1.44,
            'stock_boxes' => 50,
        ]);
    }

    private function createQuote(ProductVariant ...$variants): int
    {
        return $this->postJson('/api/quotes', [
            'customer_id' => Customer::create(['name' => 'Juan Perez'])->id,
            'items' => array_map(fn (ProductVariant $variant) => ['product_variant_id' => $variant->id, 'boxes' => 1], $variants),
        ])->assertCreated()->json('data.id');
    }

    public function test_delete_summary_returns_zero_counts_for_a_product_without_history(): void
    {
        $product = $this->createProduct();
        $this->createVariant($product, 'Taupe');

        $this->getJson("/api/products/{$product->id}/delete-summary")
            ->assertOk()
            ->assertExactJson(['data' => ['quotes_count' => 0]]);
    }

    public function test_delete_summary_counts_distinct_quotes_including_soft_deleted_variants(): void
    {
        $product = $this->createProduct();
        $taupe = $this->createVariant($product, 'Taupe');
        $gris = $this->createVariant($product, 'Gris');
        $discontinued = $this->createVariant($product, 'Blanco', '30X60');

        // Dos líneas del mismo piso en una sola cotización cuentan una vez.
        $this->createQuote($taupe, $gris);
        $this->createQuote($discontinued);

        // Otro piso: no cuenta.
        $other = $this->createVariant($this->createProduct('Habitat'), 'Taupe');
        $this->createQuote($other);

        $discontinued->delete();

        $this->getJson("/api/products/{$product->id}/delete-summary")
            ->assertOk()
            ->assertExactJson(['data' => ['quotes_count' => 2]]);
    }

    public function test_destroy_soft_deletes_the_product_and_all_its_active_variants(): void
    {
        $product = $this->createProduct();
        $variants = collect(['Taupe', 'Gris', 'Blanco'])->map(fn (string $color) => $this->createVariant($product, $color));
        $alreadyDeleted = $this->createVariant($product, 'Negro', '30X60');
        $alreadyDeleted->delete();
        $deletedAt = $alreadyDeleted->fresh()->deleted_at;

        $keptProduct = $this->createProduct('Habitat');
        $kept = $this->createVariant($keptProduct, 'Taupe');

        // Sin bloqueo: con historial también se borra.
        $this->createQuote($variants[0]);

        $this->travel(5)->minutes();

        $this->deleteJson("/api/products/{$product->id}")->assertNoContent();

        $this->assertSoftDeleted('products', ['id' => $product->id]);
        foreach ($variants as $variant) {
            $this->assertSoftDeleted('product_variants', ['id' => $variant->id]);
        }

        // La que ya estaba dada de baja conserva su fecha original.
        $this->assertTrue($alreadyDeleted->fresh()->deleted_at->equalTo($deletedAt));

        $this->assertDatabaseHas('products', ['id' => $keptProduct->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('product_variants', ['id' => $kept->id, 'deleted_at' => null]);
    }

    public function test_quotes_keep_showing_the_product_name_after_it_is_deleted(): void
    {
        $product = $this->createProduct();
        $variant = $this->createVariant($product, 'Taupe');
        $quoteId = $this->createQuote($variant);

        $this->deleteJson("/api/products/{$product->id}")->assertNoContent();

        $this->getJson('/api/quotes?with=items')
            ->assertOk()
            ->assertJsonPath('data.0.items.0.product_variant.id', $variant->id)
            ->assertJsonPath('data.0.items.0.product_variant.product.name', 'Creato');

        $this->getJson("/api/quotes/{$quoteId}")
            ->assertOk()
            ->assertJsonPath('data.items.0.product_variant.color', 'Taupe')
            ->assertJsonPath('data.items.0.product_variant.product.name', 'Creato');

        $this->assertPdfLineLabel(QuotePdfGenerator::class, "/api/quotes/{$quoteId}/pdf", 'description', 'Creato Taupe 60X120');
    }

    /**
     * @param  class-string  $generator  Generador del PDF y clave de la línea que lleva el nombre.
     */
    private function assertPdfLineLabel(string $generator, string $url, string $key, string $expected): void
    {
        $captured = null;
        $this->mock($generator, function ($mock) use (&$captured) {
            $mock->shouldReceive('generate')->once()->andReturnUsing(function (array $document) use (&$captured) {
                $captured = $document;

                return '%PDF-fake';
            });
        });

        $this->get($url)->assertOk();

        $this->assertSame($expected, $captured['items'][0][$key]);
    }

    public function test_a_deleted_product_returns_404_on_product_and_variant_endpoints(): void
    {
        $product = $this->createProduct();
        $variant = $this->createVariant($product, 'Taupe');
        $this->createVariant($product, 'Gris');

        $this->deleteJson("/api/products/{$product->id}")->assertNoContent();

        $this->getJson("/api/products/{$product->id}")->assertNotFound();
        $this->putJson("/api/products/{$product->id}", ['name' => 'Otro'])->assertNotFound();
        $this->deleteJson("/api/products/{$product->id}")->assertNotFound();
        $this->getJson("/api/products/{$product->id}/delete-summary")->assertNotFound();
        $this->deleteJson("/api/products/{$product->id}/variants", ['variant_ids' => [$variant->id]])->assertNotFound();

        $this->getJson("/api/product-variants/{$variant->id}")->assertNotFound();
        $this->putJson("/api/product-variants/{$variant->id}", ['color' => 'Otro'])->assertNotFound();
        $this->deleteJson("/api/product-variants/{$variant->id}")->assertNotFound();
        $this->getJson("/api/product-variants/{$variant->id}/stock-movements")->assertNotFound();
        $this->patchJson("/api/product-variants/{$variant->id}/stock", ['type' => 'add', 'quantity' => 1])->assertNotFound();

        $this->getJson('/api/products')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/product-variants')->assertOk()->assertJsonCount(0, 'data');

        // Tampoco se le pueden crear variantes nuevas.
        $this->postJson('/api/product-variants', [
            'product_id' => $product->id,
            'color' => 'Blanco',
            'size' => '60X120',
            'price_per_m2' => 100,
            'price_per_box' => 144,
        ])->assertStatus(422)->assertJsonValidationErrors(['product_id']);
    }
}
