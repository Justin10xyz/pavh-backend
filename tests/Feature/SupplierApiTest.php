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

    public function test_it_creates_a_supplier(): void
    {
        $this->postJson('/api/suppliers', ['name' => 'Vitromex'])
            ->assertCreated()
            ->assertJsonFragment(['name' => 'Vitromex']);

        $this->assertDatabaseHas('suppliers', ['name' => 'Vitromex']);
    }

    public function test_it_requires_a_name_to_create_a_supplier(): void
    {
        $this->postJson('/api/suppliers', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_it_rejects_a_supplier_name_longer_than_255_characters(): void
    {
        $this->postJson('/api/suppliers', ['name' => str_repeat('a', 256)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_it_rejects_a_duplicate_supplier_name(): void
    {
        Supplier::create(['name' => 'Interceramic']);

        $this->postJson('/api/suppliers', ['name' => 'Interceramic'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'Ya existe un proveedor con ese nombre.');
    }

    public function test_it_updates_a_supplier(): void
    {
        $supplier = Supplier::create(['name' => 'Interceramic']);

        $this->putJson("/api/suppliers/{$supplier->id}", ['name' => 'Interceramic SA'])
            ->assertOk()
            ->assertJsonFragment(['name' => 'Interceramic SA']);

        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id, 'name' => 'Interceramic SA']);
    }

    public function test_it_allows_keeping_the_same_name_on_update(): void
    {
        $supplier = Supplier::create(['name' => 'Interceramic']);

        $this->putJson("/api/suppliers/{$supplier->id}", ['name' => 'Interceramic'])->assertOk();
    }

    public function test_it_rejects_a_duplicate_supplier_name_on_update(): void
    {
        Supplier::create(['name' => 'Interceramic']);
        $supplier = Supplier::create(['name' => 'Vitromex']);

        $this->putJson("/api/suppliers/{$supplier->id}", ['name' => 'Interceramic'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'Ya existe un proveedor con ese nombre.');
    }

    public function test_it_allows_reusing_the_name_of_a_deleted_supplier(): void
    {
        Supplier::create(['name' => 'Interceramic'])->delete();

        $this->postJson('/api/suppliers', ['name' => 'Interceramic'])->assertCreated();

        $other = Supplier::create(['name' => 'Vitromex']);
        Supplier::create(['name' => 'Lamosa'])->delete();
        $this->putJson("/api/suppliers/{$other->id}", ['name' => 'Lamosa'])->assertOk();
    }

    public function test_it_deletes_a_supplier_without_products(): void
    {
        $supplier = Supplier::create(['name' => 'Acme']);

        $this->deleteJson("/api/suppliers/{$supplier->id}")->assertNoContent();

        $this->assertSoftDeleted('suppliers', ['id' => $supplier->id]);
        $this->getJson('/api/suppliers')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_it_blocks_deleting_a_supplier_with_one_product(): void
    {
        $supplier = Supplier::create(['name' => 'Interceramic']);
        $this->createProduct($supplier);

        $this->deleteJson("/api/suppliers/{$supplier->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'No se puede eliminar el proveedor "Interceramic": tiene 1 producto asociado. Muévelo a otro proveedor antes de eliminarlo.');

        $this->assertNotSoftDeleted('suppliers', ['id' => $supplier->id]);
    }

    public function test_it_blocks_deleting_a_supplier_with_products(): void
    {
        $supplier = Supplier::create(['name' => 'Interceramic']);
        $this->createProduct($supplier);
        $this->createProduct($supplier);

        $this->deleteJson("/api/suppliers/{$supplier->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'No se puede eliminar el proveedor "Interceramic": tiene 2 productos asociados. Muévelos a otro proveedor antes de eliminarlo.');

        $this->assertNotSoftDeleted('suppliers', ['id' => $supplier->id]);
    }

    public function test_it_ignores_soft_deleted_products_when_deleting_a_supplier(): void
    {
        $supplier = Supplier::create(['name' => 'Interceramic']);
        $product = $this->createProduct($supplier);
        $product->delete();

        $this->deleteJson("/api/suppliers/{$supplier->id}")->assertNoContent();

        $this->assertSoftDeleted('suppliers', ['id' => $supplier->id]);
        // El producto dado de baja conserva su proveedor (historial de ventas y cotizaciones).
        $this->assertSame('Interceramic', Product::withTrashed()->find($product->id)->supplier->name);
    }

    public function test_it_returns_not_found_when_deleting_an_already_deleted_supplier(): void
    {
        $supplier = Supplier::create(['name' => 'Acme']);
        $supplier->delete();

        $this->deleteJson("/api/suppliers/{$supplier->id}")->assertNotFound();
    }

    public function test_it_returns_a_delete_summary_for_a_supplier_with_products(): void
    {
        $supplier = Supplier::create(['name' => 'Interceramic']);
        $this->createProduct($supplier);
        $this->createProduct($supplier)->delete();

        $this->getJson("/api/suppliers/{$supplier->id}/delete-summary")
            ->assertOk()
            ->assertJsonPath('data.products_count', 1)
            ->assertJsonPath('data.blocked_message', fn (string $message) => str_contains($message, '1 producto asociado'));
    }

    public function test_it_returns_a_delete_summary_for_a_supplier_without_products(): void
    {
        $supplier = Supplier::create(['name' => 'Acme']);

        $this->getJson("/api/suppliers/{$supplier->id}/delete-summary")
            ->assertOk()
            ->assertJsonPath('data.products_count', 0)
            ->assertJsonPath('data.blocked_message', null);
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
