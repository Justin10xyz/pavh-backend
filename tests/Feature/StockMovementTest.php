<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SimpleProduct;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\UnitType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Support\InspectsQueryLocks;
use Tests\TestCase;

class StockMovementTest extends TestCase
{
    use InspectsQueryLocks, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('sanctum.stateful')[0] ?? 'http://localhost:5173');

        $this->actingAs(User::factory()->create());
    }

    private function createVariant(?float $m2PerBox = 1.44, ?int $stockBoxes = 10, string $code = 'PIS-CREATO-TAU-60X120'): ProductVariant
    {
        $supplier = Supplier::create(['name' => 'Interceramic']);
        $category = Category::create(['name' => 'Floor', 'code_prefix' => 'PIS']);
        $unitType = UnitType::create(['name' => 'm2']);

        $product = Product::create([
            'supplier_id' => $supplier->id,
            'category_id' => $category->id,
            'unit_type_id' => $unitType->id,
            'name' => 'Creato',
            'purchase_unit' => 'caja',
        ]);

        return ProductVariant::create([
            'product_id' => $product->id,
            'code' => $code,
            'color' => 'Taupe',
            'size' => '60x120',
            'price_per_m2' => 100.00,
            'price_per_box' => 144.00,
            'm2_per_box' => $m2PerBox,
            'stock_boxes' => $stockBoxes,
        ]);
    }

    private function createSimpleProduct(?int $stockQuantity = 20): SimpleProduct
    {
        $category = Category::create(['name' => 'Materiales', 'code_prefix' => 'MAT', 'product_form_type' => 'simple']);

        return SimpleProduct::create([
            'category_id' => $category->id,
            'name' => 'Pegazulejo gris 20kg',
            'price' => 189.50,
            'stock_quantity' => $stockQuantity,
        ]);
    }

    public function test_manual_variant_adjustments_record_movements(): void
    {
        $variant = $this->createVariant(stockBoxes: 10);

        $this->patchJson("/api/product-variants/{$variant->id}/stock", ['quantity' => 5, 'type' => 'add'])->assertOk();
        $this->patchJson("/api/product-variants/{$variant->id}/stock", ['quantity' => 3, 'type' => 'subtract'])->assertOk();

        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseHas('stock_movements', [
            'product_variant_id' => $variant->id,
            'simple_product_id' => null,
            'quantity' => 5,
            'type' => 'add',
            'source' => 'manual',
            'sale_id' => null,
            'resulting_stock' => 15,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'product_variant_id' => $variant->id,
            'quantity' => 3,
            'type' => 'subtract',
            'source' => 'manual',
            'resulting_stock' => 12,
        ]);
    }

    public function test_manual_simple_product_adjustment_records_a_movement(): void
    {
        $simpleProduct = $this->createSimpleProduct(stockQuantity: 12);

        $this->patchJson("/api/simple-products/{$simpleProduct->id}/stock", ['quantity' => 12, 'type' => 'subtract'])->assertOk();

        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('stock_movements', [
            'product_variant_id' => null,
            'simple_product_id' => $simpleProduct->id,
            'quantity' => 12,
            'type' => 'subtract',
            'source' => 'manual',
            'sale_id' => null,
            'resulting_stock' => 0,
        ]);
    }

    public function test_rejected_manual_adjustments_record_nothing(): void
    {
        $variant = $this->createVariant(stockBoxes: 2);
        $simpleProduct = $this->createSimpleProduct(stockQuantity: 2);

        $this->patchJson("/api/product-variants/{$variant->id}/stock", ['quantity' => 3, 'type' => 'subtract'])->assertUnprocessable();
        $this->patchJson("/api/simple-products/{$simpleProduct->id}/stock", ['quantity' => 3, 'type' => 'subtract'])->assertUnprocessable();
        $this->patchJson("/api/simple-products/{$simpleProduct->id}/stock", ['quantity' => 3, 'type' => 'invalid'])->assertUnprocessable();

        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_sale_records_one_movement_per_product_in_physical_stock_units(): void
    {
        // Dos líneas de la misma variante: 7 + 2 = 9 cajas.
        $variant = $this->createVariant(m2PerBox: 1.44, stockBoxes: 10);
        $simpleProduct = $this->createSimpleProduct(stockQuantity: 20);

        $saleId = $this->postJson('/api/sales', [
            'items' => [
                ['product_variant_id' => $variant->id, 'boxes' => 7, 'unit_price' => 100.00],
                ['product_variant_id' => $variant->id, 'boxes' => 2, 'unit_price' => 100.00],
                ['simple_product_id' => $simpleProduct->id, 'quantity' => 4, 'unit_price' => 189.50],
            ],
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseHas('stock_movements', [
            'product_variant_id' => $variant->id,
            'simple_product_id' => null,
            'quantity' => 9,
            'type' => 'subtract',
            'source' => 'sale',
            'sale_id' => $saleId,
            'resulting_stock' => 1,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'product_variant_id' => null,
            'simple_product_id' => $simpleProduct->id,
            'quantity' => 4,
            'type' => 'subtract',
            'source' => 'sale',
            'sale_id' => $saleId,
            'resulting_stock' => 16,
        ]);
    }

    public function test_rejected_sale_records_nothing(): void
    {
        $variant = $this->createVariant(m2PerBox: 1.44, stockBoxes: 10);
        $simpleProduct = $this->createSimpleProduct(stockQuantity: 2);

        $this->postJson('/api/sales', [
            'items' => [
                ['product_variant_id' => $variant->id, 'boxes' => 2, 'unit_price' => 100.00],
                ['simple_product_id' => $simpleProduct->id, 'quantity' => 3, 'unit_price' => 189.50],
            ],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_it_lists_a_variant_history_newest_first_with_the_sale_folio(): void
    {
        $variant = $this->createVariant(m2PerBox: 1.44, stockBoxes: 10);
        $otherVariant = $this->createVariant(stockBoxes: 10, code: 'PIS-CREATO-GRI-60X120');

        $this->travelTo(now()->subHours(2));
        $this->patchJson("/api/product-variants/{$variant->id}/stock", ['quantity' => 5, 'type' => 'add'])->assertOk();

        $this->travelBack();
        $this->travelTo(now()->subHour());
        $saleId = $this->postJson('/api/sales', [
            'items' => [['product_variant_id' => $variant->id, 'boxes' => 7, 'unit_price' => 100.00]],
        ])->assertCreated()->json('data.id');

        $this->travelBack();
        $this->patchJson("/api/product-variants/{$otherVariant->id}/stock", ['quantity' => 1, 'type' => 'add'])->assertOk();

        $this->getJson("/api/product-variants/{$variant->id}/stock-movements")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.type', 'subtract')
            ->assertJsonPath('data.0.source', 'sale')
            ->assertJsonPath('data.0.quantity', 7)
            ->assertJsonPath('data.0.resulting_stock', 8)
            ->assertJsonPath('data.0.sale_folio', 'V-0001')
            ->assertJsonPath('data.0.sale_id', $saleId)
            ->assertJsonPath('data.1.type', 'add')
            ->assertJsonPath('data.1.source', 'manual')
            ->assertJsonPath('data.1.quantity', 5)
            ->assertJsonPath('data.1.resulting_stock', 15)
            ->assertJsonPath('data.1.sale_folio', null)
            ->assertJsonPath('data.1.sale_id', null);
    }

    public function test_it_lists_a_simple_product_history(): void
    {
        $simpleProduct = $this->createSimpleProduct(stockQuantity: 20);

        $this->patchJson("/api/simple-products/{$simpleProduct->id}/stock", ['quantity' => 3, 'type' => 'subtract'])->assertOk();
        $this->postJson('/api/sales', [
            'items' => [['simple_product_id' => $simpleProduct->id, 'quantity' => 4, 'unit_price' => 189.50]],
        ])->assertCreated();

        // Mismo segundo: el desempate por id mantiene el más reciente primero.
        $this->getJson("/api/simple-products/{$simpleProduct->id}/stock-movements")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.source', 'sale')
            ->assertJsonPath('data.0.resulting_stock', 13)
            ->assertJsonPath('data.0.sale_folio', 'V-0001')
            ->assertJsonPath('data.1.source', 'manual')
            ->assertJsonPath('data.1.resulting_stock', 17);
    }

    public function test_history_keeps_the_folio_of_a_soft_deleted_sale(): void
    {
        $simpleProduct = $this->createSimpleProduct(stockQuantity: 20);

        $saleId = $this->postJson('/api/sales', [
            'items' => [['simple_product_id' => $simpleProduct->id, 'quantity' => 4, 'unit_price' => 189.50]],
        ])->assertCreated()->json('data.id');

        Sale::find($saleId)->delete();

        $this->getJson("/api/simple-products/{$simpleProduct->id}/stock-movements")
            ->assertOk()
            ->assertJsonPath('data.0.sale_folio', 'V-0001')
            ->assertJsonPath('data.0.sale_id', null);
    }

    public function test_manual_variant_adjustment_locks_the_row_before_mutating(): void
    {
        $variant = $this->createVariant(stockBoxes: 10);

        $this->logQueriesWithVisibleLocks();

        $this->patchJson("/api/product-variants/{$variant->id}/stock", ['quantity' => 3, 'type' => 'subtract'])->assertOk();

        $this->assertLockedBeforeUpdate('product_variants');
    }

    public function test_manual_simple_product_adjustment_locks_the_row_before_mutating(): void
    {
        $simpleProduct = $this->createSimpleProduct(stockQuantity: 10);

        $this->logQueriesWithVisibleLocks();

        $this->patchJson("/api/simple-products/{$simpleProduct->id}/stock", ['quantity' => 3, 'type' => 'subtract'])->assertOk();

        $this->assertLockedBeforeUpdate('simple_products');
    }

    public function test_sale_locks_every_product_before_mutating_stock(): void
    {
        $variant = $this->createVariant(m2PerBox: 1.44, stockBoxes: 10);
        $simpleProduct = $this->createSimpleProduct(stockQuantity: 20);

        $this->logQueriesWithVisibleLocks();

        $this->postJson('/api/sales', [
            'items' => [
                ['product_variant_id' => $variant->id, 'boxes' => 7, 'unit_price' => 100.00],
                ['simple_product_id' => $simpleProduct->id, 'quantity' => 4, 'unit_price' => 189.50],
            ],
        ])->assertCreated();

        $this->assertLockedBeforeUpdate('product_variants');
        $this->assertLockedBeforeUpdate('simple_products');
    }

    public function test_insufficient_stock_check_on_a_sale_runs_against_the_locked_row(): void
    {
        // stock=2 cajas, se piden 7. El rechazo debe salir
        // de la lectura bloqueada dentro de la transacción, no de una lectura
        // previa sin lock que una venta concurrente podría dejar obsoleta.
        $variant = $this->createVariant(m2PerBox: 1.44, stockBoxes: 2);

        $this->logQueriesWithVisibleLocks();

        $this->postJson('/api/sales', [
            'items' => [
                ['product_variant_id' => $variant->id, 'boxes' => 7, 'unit_price' => 100.00],
            ],
        ])->assertUnprocessable();

        $this->assertNotNull($this->lockedReadIndex('product_variants'));
        $this->assertNull($this->queryIndex('insert into "sales"'));
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_record_rejects_a_stockable_without_id_and_leaves_stock_untouched(): void
    {
        // Sin id, el movimiento no apuntaría ni a variante ni a producto simple.
        $unsaved = new SimpleProduct(['stock_quantity' => 5]);

        $this->expectException(LogicException::class);

        try {
            StockMovement::record($unsaved, 'add', 1, 'manual');
        } finally {
            $this->assertSame(5, $unsaved->stock_quantity);
            $this->assertDatabaseCount('stock_movements', 0);
        }
    }

    public function test_record_rejects_a_sale_movement_without_a_sale(): void
    {
        $simpleProduct = $this->createSimpleProduct(stockQuantity: 5);

        $this->expectException(LogicException::class);

        try {
            StockMovement::record($simpleProduct, 'subtract', 1, 'sale');
        } finally {
            $this->assertDatabaseHas('simple_products', ['id' => $simpleProduct->id, 'stock_quantity' => 5]);
            $this->assertDatabaseCount('stock_movements', 0);
        }
    }

    public function test_has_sufficient_stock_is_always_true_without_stock_tracking(): void
    {
        $variant = $this->createVariant(stockBoxes: null);
        $simpleProduct = $this->createSimpleProduct(stockQuantity: null);

        foreach ([1, 1000, PHP_INT_MAX] as $quantity) {
            $this->assertTrue($variant->hasSufficientStock($quantity));
            $this->assertTrue($simpleProduct->hasSufficientStock($quantity));
        }
    }

    public function test_add_on_untracked_products_activates_tracking_with_an_initial_movement(): void
    {
        $variant = $this->createVariant(stockBoxes: null);
        $simpleProduct = $this->createSimpleProduct(stockQuantity: null);

        $this->patchJson("/api/product-variants/{$variant->id}/stock", ['quantity' => 7, 'type' => 'add'])
            ->assertOk()
            ->assertJsonPath('data.stock_boxes', 7);

        $this->patchJson("/api/simple-products/{$simpleProduct->id}/stock", ['quantity' => 4, 'type' => 'add'])
            ->assertOk()
            ->assertJsonPath('data.stock_quantity', 4);

        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'stock_boxes' => 7]);
        $this->assertDatabaseHas('simple_products', ['id' => $simpleProduct->id, 'stock_quantity' => 4]);

        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseHas('stock_movements', [
            'product_variant_id' => $variant->id,
            'quantity' => 7,
            'type' => 'add',
            'source' => 'initial',
            'resulting_stock' => 7,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'simple_product_id' => $simpleProduct->id,
            'quantity' => 4,
            'type' => 'add',
            'source' => 'initial',
            'resulting_stock' => 4,
        ]);

        // Ya con seguimiento, los siguientes ajustes son manuales.
        $this->patchJson("/api/product-variants/{$variant->id}/stock", ['quantity' => 2, 'type' => 'subtract'])
            ->assertOk()
            ->assertJsonPath('data.stock_boxes', 5);

        $this->assertDatabaseHas('stock_movements', [
            'product_variant_id' => $variant->id,
            'type' => 'subtract',
            'source' => 'manual',
            'resulting_stock' => 5,
        ]);
    }

    public function test_add_zero_on_untracked_products_activates_tracking_from_zero(): void
    {
        $variant = $this->createVariant(stockBoxes: null);
        $simpleProduct = $this->createSimpleProduct(stockQuantity: null);

        $this->patchJson("/api/product-variants/{$variant->id}/stock", ['quantity' => 0, 'type' => 'add'])
            ->assertOk()
            ->assertJsonPath('data.stock_boxes', 0);

        $this->patchJson("/api/simple-products/{$simpleProduct->id}/stock", ['quantity' => 0, 'type' => 'add'])
            ->assertOk()
            ->assertJsonPath('data.stock_quantity', 0);

        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'stock_boxes' => 0]);
        $this->assertDatabaseHas('simple_products', ['id' => $simpleProduct->id, 'stock_quantity' => 0]);

        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseHas('stock_movements', [
            'product_variant_id' => $variant->id,
            'quantity' => 0,
            'type' => 'add',
            'source' => 'initial',
            'resulting_stock' => 0,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'simple_product_id' => $simpleProduct->id,
            'quantity' => 0,
            'type' => 'add',
            'source' => 'initial',
            'resulting_stock' => 0,
        ]);
    }

    public function test_zero_quantity_on_tracked_products_is_rejected_and_records_nothing(): void
    {
        $variant = $this->createVariant(stockBoxes: 10);
        $simpleProduct = $this->createSimpleProduct(stockQuantity: 0);

        foreach (['add', 'subtract'] as $type) {
            $this->patchJson("/api/product-variants/{$variant->id}/stock", ['quantity' => 0, 'type' => $type])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('quantity');

            $this->patchJson("/api/simple-products/{$simpleProduct->id}/stock", ['quantity' => 0, 'type' => $type])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('quantity');
        }

        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'stock_boxes' => 10]);
        $this->assertDatabaseHas('simple_products', ['id' => $simpleProduct->id, 'stock_quantity' => 0]);
    }

    public function test_negative_quantity_is_rejected_by_validation(): void
    {
        $variant = $this->createVariant(stockBoxes: null);

        $this->patchJson("/api/product-variants/{$variant->id}/stock", ['quantity' => -1, 'type' => 'add'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity');

        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_subtract_on_untracked_products_is_rejected_and_records_nothing(): void
    {
        $variant = $this->createVariant(stockBoxes: null);
        $simpleProduct = $this->createSimpleProduct(stockQuantity: null);

        $this->patchJson("/api/product-variants/{$variant->id}/stock", ['quantity' => 3, 'type' => 'subtract'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Este producto no tiene seguimiento de stock activo.');

        $this->patchJson("/api/simple-products/{$simpleProduct->id}/stock", ['quantity' => 3, 'type' => 'subtract'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Este producto no tiene seguimiento de stock activo.');

        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'stock_boxes' => null]);
        $this->assertDatabaseHas('simple_products', ['id' => $simpleProduct->id, 'stock_quantity' => null]);
    }

    public function test_variant_update_ignores_stock_boxes(): void
    {
        $tracked = $this->createVariant(stockBoxes: 10);
        $untracked = $this->createVariant(stockBoxes: null, code: 'PIS-CREATO-GRA-60X120');

        $this->putJson("/api/product-variants/{$tracked->id}", ['color' => 'Gris', 'stock_boxes' => 999])
            ->assertOk()
            ->assertJsonPath('data.color', 'Gris')
            ->assertJsonPath('data.stock_boxes', 10);

        $this->putJson("/api/product-variants/{$untracked->id}", ['stock_boxes' => 0])
            ->assertOk()
            ->assertJsonPath('data.stock_boxes', null);

        $this->assertDatabaseHas('product_variants', ['id' => $tracked->id, 'stock_boxes' => 10]);
        $this->assertDatabaseHas('product_variants', ['id' => $untracked->id, 'stock_boxes' => null]);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_sale_of_untracked_products_is_not_blocked_and_records_nothing(): void
    {
        $variant = $this->createVariant(m2PerBox: 1.44, stockBoxes: null);
        $simpleProduct = $this->createSimpleProduct(stockQuantity: null);

        $this->postJson('/api/sales', [
            'items' => [
                ['product_variant_id' => $variant->id, 'boxes' => 50, 'unit_price' => 100.00],
                ['simple_product_id' => $simpleProduct->id, 'quantity' => 40, 'unit_price' => 189.50],
            ],
        ])->assertCreated();

        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'stock_boxes' => null]);
        $this->assertDatabaseHas('simple_products', ['id' => $simpleProduct->id, 'stock_quantity' => null]);
    }

    public function test_untracked_variant_is_never_low_stock(): void
    {
        $variant = $this->createVariant(stockBoxes: null);
        $variant->update(['minimum_stock' => 1]);

        $this->assertFalse($variant->isLowStock());

        $this->getJson("/api/product-variants/{$variant->id}")
            ->assertOk()
            ->assertJsonPath('data.stock_boxes', null)
            ->assertJsonPath('data.low_stock', false);

        $this->getJson('/api/product-variants?low_stock=1')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_record_rejects_subtracting_from_an_untracked_product(): void
    {
        $simpleProduct = $this->createSimpleProduct(stockQuantity: null);

        $this->expectException(LogicException::class);

        try {
            StockMovement::record($simpleProduct, 'subtract', 1, 'initial');
        } finally {
            $this->assertDatabaseHas('simple_products', ['id' => $simpleProduct->id, 'stock_quantity' => null]);
            $this->assertDatabaseCount('stock_movements', 0);
        }
    }

    public function test_record_rejects_a_manual_movement_on_an_untracked_product(): void
    {
        $simpleProduct = $this->createSimpleProduct(stockQuantity: null);

        $this->expectException(LogicException::class);

        try {
            StockMovement::record($simpleProduct, 'add', 1, 'manual');
        } finally {
            $this->assertDatabaseHas('simple_products', ['id' => $simpleProduct->id, 'stock_quantity' => null]);
            $this->assertDatabaseCount('stock_movements', 0);
        }
    }

    public function test_record_rejects_an_initial_movement_on_a_tracked_product(): void
    {
        $simpleProduct = $this->createSimpleProduct(stockQuantity: 5);

        $this->expectException(LogicException::class);

        try {
            StockMovement::record($simpleProduct, 'add', 1, 'initial');
        } finally {
            $this->assertDatabaseHas('simple_products', ['id' => $simpleProduct->id, 'stock_quantity' => 5]);
            $this->assertDatabaseCount('stock_movements', 0);
        }
    }

    public function test_record_rejects_a_zero_quantity_outside_the_initial_movement(): void
    {
        $simpleProduct = $this->createSimpleProduct(stockQuantity: 5);

        $this->expectException(LogicException::class);

        try {
            StockMovement::record($simpleProduct, 'add', 0, 'manual');
        } finally {
            $this->assertDatabaseCount('stock_movements', 0);
        }
    }

    public function test_deactivate_removes_tracking_and_records_the_stock_it_had(): void
    {
        $variant = $this->createVariant(stockBoxes: 8);
        $simpleProduct = $this->createSimpleProduct(stockQuantity: 0);

        $this->patchJson("/api/product-variants/{$variant->id}/stock", ['type' => 'deactivate'])
            ->assertOk()
            ->assertJsonPath('data.stock_boxes', null)
            ->assertJsonPath('data.low_stock', false);

        $this->patchJson("/api/simple-products/{$simpleProduct->id}/stock", ['type' => 'deactivate'])
            ->assertOk()
            ->assertJsonPath('data.stock_quantity', null);

        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'stock_boxes' => null]);
        $this->assertDatabaseHas('simple_products', ['id' => $simpleProduct->id, 'stock_quantity' => null]);

        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseHas('stock_movements', [
            'product_variant_id' => $variant->id,
            'quantity' => 8,
            'type' => 'subtract',
            'source' => 'deactivated',
            'sale_id' => null,
            'resulting_stock' => null,
        ]);
        // Sin existencias también se puede desactivar: el movimiento queda en 0.
        $this->assertDatabaseHas('stock_movements', [
            'simple_product_id' => $simpleProduct->id,
            'quantity' => 0,
            'type' => 'subtract',
            'source' => 'deactivated',
            'resulting_stock' => null,
        ]);

        $this->getJson("/api/product-variants/{$variant->id}/stock-movements")
            ->assertOk()
            ->assertJsonPath('data.0.source', 'deactivated')
            ->assertJsonPath('data.0.resulting_stock', null);
    }

    public function test_deactivate_on_untracked_products_is_rejected_and_records_nothing(): void
    {
        $variant = $this->createVariant(stockBoxes: null);
        $simpleProduct = $this->createSimpleProduct(stockQuantity: null);

        $this->patchJson("/api/product-variants/{$variant->id}/stock", ['type' => 'deactivate'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Este producto ya no tiene seguimiento de stock.');

        $this->patchJson("/api/simple-products/{$simpleProduct->id}/stock", ['type' => 'deactivate'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Este producto ya no tiene seguimiento de stock.');

        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_deactivated_products_behave_as_untracked_and_can_be_reactivated(): void
    {
        $variant = $this->createVariant(stockBoxes: 2);
        $variant->update(['minimum_stock' => 5]);
        $simpleProduct = $this->createSimpleProduct(stockQuantity: 3);

        $this->assertTrue($variant->isLowStock());

        $this->patchJson("/api/product-variants/{$variant->id}/stock", ['type' => 'deactivate'])->assertOk();
        $this->patchJson("/api/simple-products/{$simpleProduct->id}/stock", ['type' => 'deactivate'])->assertOk();

        $variant->refresh();
        $simpleProduct->refresh();

        $this->assertFalse($variant->tracksStock());
        $this->assertTrue($variant->hasSufficientStock(1000));
        $this->assertFalse($variant->isLowStock());
        $this->assertFalse($simpleProduct->tracksStock());
        $this->assertTrue($simpleProduct->hasSufficientStock(1000));

        $this->getJson('/api/product-variants?low_stock=1')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Reactivar es otra vez un 'initial', partiendo de 0.
        $this->patchJson("/api/product-variants/{$variant->id}/stock", ['quantity' => 4, 'type' => 'add'])
            ->assertOk()
            ->assertJsonPath('data.stock_boxes', 4);
        $this->patchJson("/api/simple-products/{$simpleProduct->id}/stock", ['quantity' => 0, 'type' => 'add'])
            ->assertOk()
            ->assertJsonPath('data.stock_quantity', 0);

        $this->assertDatabaseHas('stock_movements', [
            'product_variant_id' => $variant->id,
            'quantity' => 4,
            'type' => 'add',
            'source' => 'initial',
            'resulting_stock' => 4,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'simple_product_id' => $simpleProduct->id,
            'quantity' => 0,
            'source' => 'initial',
            'resulting_stock' => 0,
        ]);
    }

    public function test_deactivate_ignores_a_quantity_sent_with_it(): void
    {
        $variant = $this->createVariant(stockBoxes: 6);

        foreach ([3, 0, -5, 'abc'] as $quantity) {
            $variant->refresh();
            if (! $variant->tracksStock()) {
                $this->patchJson("/api/product-variants/{$variant->id}/stock", ['quantity' => 6, 'type' => 'add'])->assertOk();
            }

            $this->patchJson("/api/product-variants/{$variant->id}/stock", ['quantity' => $quantity, 'type' => 'deactivate'])
                ->assertOk()
                ->assertJsonPath('data.stock_boxes', null);

            $this->assertSame(6, StockMovement::where('source', 'deactivated')->latest('id')->value('quantity'));
        }

        $this->assertSame(4, StockMovement::where('source', 'deactivated')->count());
    }

    public function test_record_rejects_deactivating_an_untracked_product(): void
    {
        $simpleProduct = $this->createSimpleProduct(stockQuantity: null);

        $this->expectException(LogicException::class);

        try {
            StockMovement::record($simpleProduct, 'subtract', 0, 'deactivated');
        } finally {
            $this->assertDatabaseHas('simple_products', ['id' => $simpleProduct->id, 'stock_quantity' => null]);
            $this->assertDatabaseCount('stock_movements', 0);
        }
    }

    public function test_record_rejects_a_deactivation_that_does_not_remove_the_whole_stock(): void
    {
        $simpleProduct = $this->createSimpleProduct(stockQuantity: 5);

        foreach ([['subtract', 3], ['add', 5]] as [$type, $quantity]) {
            try {
                StockMovement::record($simpleProduct, $type, $quantity, 'deactivated');
                $this->fail("Se esperaba LogicException para {$type} {$quantity}.");
            } catch (LogicException) {
                $this->assertDatabaseHas('simple_products', ['id' => $simpleProduct->id, 'stock_quantity' => 5]);
                $this->assertDatabaseCount('stock_movements', 0);
            }
        }
    }

    private function variantPayload(int $productId, string $color, array $overrides = []): array
    {
        return [
            'product_id' => $productId,
            'color' => $color,
            'size' => '60x120',
            'price_per_m2' => 100.00,
            'price_per_box' => 144.00,
            'm2_per_box' => 1.44,
            ...$overrides,
        ];
    }

    public function test_creating_a_variant_with_stock_records_an_initial_movement(): void
    {
        // Variante creada directo (sin movimiento) solo para tener el producto.
        $productId = $this->createVariant()->product_id;

        $variantId = $this->postJson('/api/product-variants', $this->variantPayload($productId, 'Gris', ['stock_boxes' => 10]))
            ->assertCreated()
            ->assertJsonPath('data.stock_boxes', 10)
            ->json('data.id');

        $this->assertDatabaseHas('product_variants', ['id' => $variantId, 'stock_boxes' => 10]);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('stock_movements', [
            'product_variant_id' => $variantId,
            'simple_product_id' => null,
            'quantity' => 10,
            'type' => 'add',
            'source' => 'initial',
            'sale_id' => null,
            'resulting_stock' => 10,
        ]);
    }

    public function test_creating_a_variant_with_zero_stock_tracks_it_from_zero(): void
    {
        $productId = $this->createVariant()->product_id;

        $variantId = $this->postJson('/api/product-variants', $this->variantPayload($productId, 'Gris', ['stock_boxes' => 0]))
            ->assertCreated()
            ->assertJsonPath('data.stock_boxes', 0)
            ->json('data.id');

        $this->assertDatabaseHas('stock_movements', [
            'product_variant_id' => $variantId,
            'quantity' => 0,
            'source' => 'initial',
            'resulting_stock' => 0,
        ]);
    }

    public function test_creating_a_variant_without_stock_leaves_it_untracked(): void
    {
        $productId = $this->createVariant()->product_id;

        $omittedId = $this->postJson('/api/product-variants', $this->variantPayload($productId, 'Gris'))
            ->assertCreated()
            ->assertJsonPath('data.stock_boxes', null)
            ->json('data.id');

        $nullId = $this->postJson('/api/product-variants', $this->variantPayload($productId, 'Ivory', ['stock_boxes' => null]))
            ->assertCreated()
            ->assertJsonPath('data.stock_boxes', null)
            ->json('data.id');

        $this->assertDatabaseHas('product_variants', ['id' => $omittedId, 'stock_boxes' => null]);
        $this->assertDatabaseHas('product_variants', ['id' => $nullId, 'stock_boxes' => null]);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_creating_a_variant_rejects_negative_stock(): void
    {
        $productId = $this->createVariant()->product_id;

        $this->postJson('/api/product-variants', $this->variantPayload($productId, 'Gris', ['stock_boxes' => -1]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('stock_boxes');

        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_creating_a_simple_product_with_stock_records_an_initial_movement(): void
    {
        $categoryId = $this->createSimpleProduct()->category_id;

        $productId = $this->postJson('/api/simple-products', [
            'category_id' => $categoryId,
            'name' => 'Boquilla blanca 5kg',
            'price' => 99.00,
            'stock_quantity' => 10,
        ])->assertCreated()
            ->assertJsonPath('data.stock_quantity', 10)
            ->json('data.id');

        $this->assertDatabaseHas('simple_products', ['id' => $productId, 'stock_quantity' => 10]);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('stock_movements', [
            'product_variant_id' => null,
            'simple_product_id' => $productId,
            'quantity' => 10,
            'type' => 'add',
            'source' => 'initial',
            'sale_id' => null,
            'resulting_stock' => 10,
        ]);
    }

    public function test_creating_a_simple_product_without_stock_leaves_it_untracked(): void
    {
        $categoryId = $this->createSimpleProduct()->category_id;

        $omittedId = $this->postJson('/api/simple-products', [
            'category_id' => $categoryId,
            'name' => 'Boquilla blanca 5kg',
            'price' => 99.00,
        ])->assertCreated()
            ->assertJsonPath('data.stock_quantity', null)
            ->json('data.id');

        $nullId = $this->postJson('/api/simple-products', [
            'category_id' => $categoryId,
            'name' => 'Boquilla gris 5kg',
            'price' => 99.00,
            'stock_quantity' => null,
        ])->assertCreated()
            ->assertJsonPath('data.stock_quantity', null)
            ->json('data.id');

        $this->assertDatabaseHas('simple_products', ['id' => $omittedId, 'stock_quantity' => null]);
        $this->assertDatabaseHas('simple_products', ['id' => $nullId, 'stock_quantity' => null]);
        $this->assertDatabaseCount('stock_movements', 0);
    }
}
