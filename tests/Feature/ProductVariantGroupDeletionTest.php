<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quote;
use App\Models\QuoteStatus;
use App\Models\Supplier;
use App\Models\UnitType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductVariantGroupDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('sanctum.stateful')[0] ?? 'http://localhost:5173');

        $this->actingAs(User::factory()->create());
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

    private function createVariant(Product $product, string $color, string $size): ProductVariant
    {
        return ProductVariant::create([
            'product_id' => $product->id,
            'code' => strtoupper("PIS-{$product->name}-{$color}-{$size}"),
            'color' => $color,
            'size' => $size,
            'price_per_m2' => 359.00,
            'price_per_box' => 516.96,
            'm2_per_box' => 1.44,
        ]);
    }

    public function test_it_deletes_a_full_size_and_leaves_the_other_sizes_intact(): void
    {
        $product = $this->createProduct();
        $kept = $this->createVariant($product, 'Taupe', '60X120');
        $group = collect(['Taupe', 'Gris', 'Blanco'])
            ->map(fn (string $color) => $this->createVariant($product, $color, '30X60'));

        $this->deleteJson("/api/products/{$product->id}/variants", [
            'variant_ids' => $group->pluck('id')->all(),
        ])->assertNoContent();

        foreach ($group as $variant) {
            $this->assertSoftDeleted('product_variants', ['id' => $variant->id]);
        }

        $this->assertDatabaseHas('product_variants', ['id' => $kept->id, 'deleted_at' => null]);
        $this->assertSame([$kept->id], $product->variants()->pluck('id')->all());
    }

    public function test_it_rejects_deleting_the_only_size_of_a_product(): void
    {
        $product = $this->createProduct();
        $variants = collect(['Taupe', 'Gris'])
            ->map(fn (string $color) => $this->createVariant($product, $color, '60X120'));

        $this->deleteJson("/api/products/{$product->id}/variants", [
            'variant_ids' => $variants->pluck('id')->all(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Es la única medida de este producto; borra el producto completo en su lugar.');

        $this->assertSame(2, $product->variants()->count());
    }

    public function test_it_rejects_a_variant_that_belongs_to_another_product(): void
    {
        $product = $this->createProduct();
        $own = $this->createVariant($product, 'Taupe', '30X60');
        $this->createVariant($product, 'Taupe', '60X120');

        $other = $this->createProduct('Habitat');
        $foreign = $this->createVariant($other, 'Gris', '30X60');
        $this->createVariant($other, 'Gris', '60X120');

        $this->deleteJson("/api/products/{$product->id}/variants", [
            'variant_ids' => [$own->id, $foreign->id],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['variant_ids.1'])
            ->assertJsonPath('errors', fn (array $errors) => str_contains($errors['variant_ids.1'][0], (string) $foreign->id));

        $this->assertDatabaseHas('product_variants', ['id' => $own->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('product_variants', ['id' => $foreign->id, 'deleted_at' => null]);
    }

    public function test_it_rejects_a_variant_that_is_already_deleted(): void
    {
        $product = $this->createProduct();
        $deleted = $this->createVariant($product, 'Taupe', '30X60');
        $this->createVariant($product, 'Taupe', '60X120');
        $deleted->delete();

        $this->deleteJson("/api/products/{$product->id}/variants", [
            'variant_ids' => [$deleted->id],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['variant_ids.0']);
    }

    public function test_deleted_variants_remain_visible_with_trashed_in_quotes(): void
    {
        $product = $this->createProduct();
        $this->createVariant($product, 'Taupe', '60X120');
        $discontinued = $this->createVariant($product, 'Gris', '30X60');

        $quote = Quote::create([
            'folio' => 'COT-0001',
            'customer_id' => Customer::create(['name' => 'Juan Perez'])->id,
            'quote_status_id' => QuoteStatus::firstOrCreate(['name' => 'Borrador'])->id,
            'subtotal' => 744.42,
            'total' => 744.42,
        ]);
        $quoteItem = $quote->items()->create([
            'product_variant_id' => $discontinued->id,
            'boxes' => 1,
            'quantity' => 1.44,
            'unit_price' => 359.00,
            'line_total' => 516.96,
        ]);

        $this->deleteJson("/api/products/{$product->id}/variants", [
            'variant_ids' => [$discontinued->id],
        ])->assertNoContent();

        $this->assertSoftDeleted('product_variants', ['id' => $discontinued->id]);

        $this->assertSame(
            $discontinued->id,
            $quoteItem->productVariant()->withTrashed()->first()?->id,
        );

        $this->getJson("/api/quotes/{$quote->id}")
            ->assertOk()
            ->assertJsonPath('data.items.0.product_variant.id', $discontinued->id)
            ->assertJsonPath('data.items.0.product_variant.size', '30X60');
    }
}
