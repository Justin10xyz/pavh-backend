<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\FloorStyle;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\UnitType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FloorStyleApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('sanctum.stateful')[0] ?? 'http://localhost:5173');

        $this->actingAs(User::factory()->create());
    }

    public function test_it_lists_floor_styles(): void
    {
        FloorStyle::create(['name' => 'Madera']);
        FloorStyle::create(['name' => 'Mármol']);

        $this->getJson('/api/floor-styles')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['name' => 'Madera'])
            ->assertJsonFragment(['name' => 'Mármol']);
    }

    public function test_it_does_not_list_deleted_floor_styles(): void
    {
        FloorStyle::create(['name' => 'Madera']);
        FloorStyle::create(['name' => 'Mármol'])->delete();

        $this->getJson('/api/floor-styles')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonMissing(['name' => 'Mármol']);
    }

    public function test_it_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/floor-styles')->assertUnauthorized();
    }

    public function test_it_creates_a_floor_style(): void
    {
        $this->postJson('/api/floor-styles', ['name' => 'Cemento'])
            ->assertCreated()
            ->assertJsonFragment(['name' => 'Cemento']);

        $this->assertDatabaseHas('floor_styles', ['name' => 'Cemento']);
    }

    public function test_it_requires_a_name_to_create_a_floor_style(): void
    {
        $this->postJson('/api/floor-styles', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_it_rejects_a_floor_style_name_longer_than_255_characters(): void
    {
        $this->postJson('/api/floor-styles', ['name' => str_repeat('a', 256)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_it_rejects_a_duplicate_floor_style_name(): void
    {
        FloorStyle::create(['name' => 'Madera']);

        $this->postJson('/api/floor-styles', ['name' => 'Madera'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'Ya existe un estilo de piso con ese nombre.');
    }

    public function test_it_updates_a_floor_style(): void
    {
        $floorStyle = FloorStyle::create(['name' => 'Madera']);

        $this->putJson("/api/floor-styles/{$floorStyle->id}", ['name' => 'Madera rústica'])
            ->assertOk()
            ->assertJsonFragment(['name' => 'Madera rústica']);

        $this->assertDatabaseHas('floor_styles', ['id' => $floorStyle->id, 'name' => 'Madera rústica']);
    }

    public function test_it_allows_keeping_the_same_name_on_update(): void
    {
        $floorStyle = FloorStyle::create(['name' => 'Madera']);

        $this->putJson("/api/floor-styles/{$floorStyle->id}", ['name' => 'Madera'])->assertOk();
    }

    public function test_it_rejects_a_duplicate_floor_style_name_on_update(): void
    {
        FloorStyle::create(['name' => 'Madera']);
        $floorStyle = FloorStyle::create(['name' => 'Mármol']);

        $this->putJson("/api/floor-styles/{$floorStyle->id}", ['name' => 'Madera'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'Ya existe un estilo de piso con ese nombre.');
    }

    public function test_it_allows_reusing_the_name_of_a_deleted_floor_style(): void
    {
        FloorStyle::create(['name' => 'Madera'])->delete();

        $this->postJson('/api/floor-styles', ['name' => 'Madera'])->assertCreated();

        $other = FloorStyle::create(['name' => 'Mármol']);
        FloorStyle::create(['name' => 'Cemento'])->delete();
        $this->putJson("/api/floor-styles/{$other->id}", ['name' => 'Cemento'])->assertOk();
    }

    public function test_it_deletes_a_floor_style_without_products(): void
    {
        $floorStyle = FloorStyle::create(['name' => 'Madera']);

        $this->deleteJson("/api/floor-styles/{$floorStyle->id}")->assertNoContent();

        $this->assertSoftDeleted('floor_styles', ['id' => $floorStyle->id]);
        $this->getJson('/api/floor-styles')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_it_blocks_deleting_a_floor_style_with_one_product(): void
    {
        $floorStyle = FloorStyle::create(['name' => 'Madera']);
        $this->createProduct($floorStyle);

        $this->deleteJson("/api/floor-styles/{$floorStyle->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'No se puede eliminar el estilo de piso "Madera": tiene 1 producto asociado. Muévelo a otro estilo de piso antes de eliminarlo.');

        $this->assertNotSoftDeleted('floor_styles', ['id' => $floorStyle->id]);
    }

    public function test_it_blocks_deleting_a_floor_style_with_products(): void
    {
        $floorStyle = FloorStyle::create(['name' => 'Madera']);
        $this->createProduct($floorStyle);
        $this->createProduct($floorStyle);

        $this->deleteJson("/api/floor-styles/{$floorStyle->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'No se puede eliminar el estilo de piso "Madera": tiene 2 productos asociados. Muévelos a otro estilo de piso antes de eliminarlo.');

        $this->assertNotSoftDeleted('floor_styles', ['id' => $floorStyle->id]);
    }

    public function test_it_ignores_soft_deleted_products_when_deleting_a_floor_style(): void
    {
        $floorStyle = FloorStyle::create(['name' => 'Madera']);
        $product = $this->createProduct($floorStyle);
        $product->delete();

        $this->deleteJson("/api/floor-styles/{$floorStyle->id}")->assertNoContent();

        $this->assertSoftDeleted('floor_styles', ['id' => $floorStyle->id]);
        // El producto dado de baja conserva su estilo de piso.
        $this->assertSame('Madera', Product::withTrashed()->find($product->id)->floorStyle->name);
    }

    public function test_it_returns_not_found_when_deleting_an_already_deleted_floor_style(): void
    {
        $floorStyle = FloorStyle::create(['name' => 'Madera']);
        $floorStyle->delete();

        $this->deleteJson("/api/floor-styles/{$floorStyle->id}")->assertNotFound();
    }

    public function test_it_returns_a_delete_summary_for_a_floor_style_with_products(): void
    {
        $floorStyle = FloorStyle::create(['name' => 'Madera']);
        $this->createProduct($floorStyle);
        $this->createProduct($floorStyle)->delete();

        $this->getJson("/api/floor-styles/{$floorStyle->id}/delete-summary")
            ->assertOk()
            ->assertJsonPath('data.products_count', 1)
            ->assertJsonPath('data.blocked_message', fn (string $message) => str_contains($message, '1 producto asociado'));
    }

    public function test_it_returns_a_delete_summary_for_a_floor_style_without_products(): void
    {
        $floorStyle = FloorStyle::create(['name' => 'Madera']);

        $this->getJson("/api/floor-styles/{$floorStyle->id}/delete-summary")
            ->assertOk()
            ->assertJsonPath('data.products_count', 0)
            ->assertJsonPath('data.blocked_message', null);
    }

    private function createProduct(FloorStyle $floorStyle): Product
    {
        return Product::create([
            'supplier_id' => Supplier::firstOrCreate(['name' => 'Interceramic'])->id,
            'floor_style_id' => $floorStyle->id,
            'category_id' => Category::firstOrCreate(['name' => 'Pisos'], ['code_prefix' => 'PI'])->id,
            'unit_type_id' => UnitType::firstOrCreate(['name' => 'm2'])->id,
            'name' => 'Creato',
            'purchase_unit' => 'Caja',
        ]);
    }
}
