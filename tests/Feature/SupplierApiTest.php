<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\UnitType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('sanctum.stateful')[0] ?? 'http://localhost:5173');

        $this->actingAs(User::factory()->create());
    }

    public function test_it_lists_suppliers(): void
    {
        Supplier::create(['name' => 'Acme']);
        Supplier::create(['name' => 'Globex']);

        $this->getJson('/api/suppliers')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['name' => 'Acme'])
            ->assertJsonFragment(['name' => 'Globex']);
    }

    public function test_it_does_not_list_deleted_suppliers(): void
    {
        Supplier::create(['name' => 'Acme']);
        Supplier::create(['name' => 'Globex'])->delete();

        $this->getJson('/api/suppliers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonMissing(['name' => 'Globex']);
    }

    public function test_a_product_keeps_showing_its_deleted_supplier(): void
    {
        $supplier = Supplier::create(['name' => 'Interceramic']);
        $product = $this->createProduct($supplier);
        $supplier->delete();

        $this->getJson("/api/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.supplier_id', $supplier->id)
            ->assertJsonPath('data.supplier', 'Interceramic');

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('data.0.supplier', 'Interceramic');
    }

    public function test_it_rejects_creating_a_product_with_a_deleted_supplier(): void
    {
        $deleted = Supplier::create(['name' => 'Viejo']);
        $deleted->delete();

        $this->postJson('/api/products', [
            'supplier_id' => $deleted->id,
            'category_id' => Category::create(['name' => 'Pisos', 'code_prefix' => 'PI'])->id,
            'unit_type_id' => UnitType::firstOrCreate(['name' => 'm2'])->id,
            'name' => 'Creato',
            'purchase_unit' => 'Caja',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['supplier_id']);
    }

    public function test_it_rejects_assigning_a_deleted_supplier_to_a_product(): void
    {
        $product = $this->createProduct(Supplier::create(['name' => 'Interceramic']));
        $deleted = Supplier::create(['name' => 'Viejo']);
        $deleted->delete();

        $this->putJson("/api/products/{$product->id}", ['supplier_id' => $deleted->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['supplier_id']);
    }

    public function test_it_allows_updating_a_product_that_still_has_its_supplier(): void
    {
        $product = $this->createProduct(Supplier::create(['name' => 'Interceramic']));

        $this->putJson("/api/products/{$product->id}", ['name' => 'Creato 2'])->assertOk();
    }

    public function test_deletion_is_not_blocked_for_a_supplier_without_products(): void
    {
        $supplier = Supplier::create(['name' => 'Acme']);

        $this->assertSame(0, $supplier->activeProductsCount());
        $this->assertNull($supplier->deletionBlockedMessage());
    }

    public function test_deletion_is_blocked_for_a_supplier_with_products(): void
    {
        $supplier = Supplier::create(['name' => 'Interceramic']);
        $this->createProduct($supplier);

        $this->assertSame(
            'No se puede eliminar el proveedor "Interceramic": tiene 1 producto asociado. Muévelo a otro proveedor antes de eliminarlo.',
            $supplier->deletionBlockedMessage(),
        );

        $this->createProduct($supplier);

        $this->assertSame(
            'No se puede eliminar el proveedor "Interceramic": tiene 2 productos asociados. Muévelos a otro proveedor antes de eliminarlo.',
            $supplier->deletionBlockedMessage(),
        );
    }

    public function test_deletion_ignores_soft_deleted_products(): void
    {
        $supplier = Supplier::create(['name' => 'Interceramic']);
        $this->createProduct($supplier)->delete();

        $this->assertSame(0, $supplier->activeProductsCount());
        $this->assertNull($supplier->deletionBlockedMessage());
    }

    private function createProduct(Supplier $supplier): Product
    {
        return Product::create([
            'supplier_id' => $supplier->id,
            'category_id' => Category::firstOrCreate(['name' => 'Pisos'], ['code_prefix' => 'PI'])->id,
            'unit_type_id' => UnitType::firstOrCreate(['name' => 'm2'])->id,
            'name' => 'Creato',
            'purchase_unit' => 'Caja',
        ]);
    }
}
