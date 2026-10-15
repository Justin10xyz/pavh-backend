<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\AdjustStockRequest;
use App\Http\Requests\Inventory\StoreProductVariantRequest;
use App\Http\Requests\Inventory\UpdateProductVariantRequest;
use App\Http\Resources\Inventory\ProductVariantResource;
use App\Http\Resources\Inventory\StockMovementResource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Services\VariantCodeGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductVariantController extends Controller
{
    public function index(Request $request)
    {
        $variants = ProductVariant::query()
            ->when($request->boolean('low_stock'), fn ($query) => $query->lowStock())
            ->when($request->filled('category_id'), fn ($query) => $query->byCategory((int) $request->query('category_id')))
            ->get();

        return ProductVariantResource::collection($variants);
    }

    public function show(ProductVariant $productVariant)
    {
        return new ProductVariantResource($productVariant);
    }

    public function store(StoreProductVariantRequest $request, VariantCodeGenerator $codeGenerator)
    {
        $data = $request->validated();

        $product = Product::findOrFail($data['product_id']);

        $data['code'] = $codeGenerator->generate($product, $data['color'], $data['size']);

        // El stock inicial no se guarda directo: entra como movimiento
        // 'initial' para que quede en el historial. Sin stock_boxes la
        // variante se crea sin seguimiento.
        $initialStock = $data['stock_boxes'] ?? null;
        unset($data['stock_boxes']);

        $variant = DB::transaction(function () use ($data, $initialStock) {
            $variant = ProductVariant::create($data);

            if ($initialStock !== null) {
                StockMovement::record($variant, 'add', $initialStock, 'initial');
            }

            return $variant;
        });

        return new ProductVariantResource($variant->refresh());
    }

    public function update(UpdateProductVariantRequest $request, ProductVariant $productVariant)
    {
        $productVariant->update($request->validated());

        return new ProductVariantResource($productVariant);
    }

    public function destroy(ProductVariant $productVariant)
    {
        if ($productVariant->product->variants()->count() <= 1) {
            return response()->json([
                'message' => 'No se puede eliminar la última variante de un producto.',
            ], 422);
        }

        $productVariant->delete();

        return response()->noContent();
    }

    /**
     * Historial completo, sin paginar (mismo criterio que los demás listados).
     * La venta se carga con withTrashed() para conservar el folio aunque la
     * venta se haya dado de baja.
     */
    public function stockMovements(ProductVariant $productVariant)
    {
        $movements = $productVariant->stockMovements()
            ->with(['sale' => fn ($query) => $query->withTrashed()])
            ->latest()
            ->latest('id')
            ->get();

        return StockMovementResource::collection($movements);
    }

    public function adjustStock(AdjustStockRequest $request, ProductVariant $productVariant)
    {
        $data = $request->validated();

        // Se relee con lockForUpdate() dentro de la transacción: el chequeo de
        // stock suficiente y el resulting_stock del historial deben ver el
        // stock real, no el de un ajuste/venta concurrente que aún no termina.
        return DB::transaction(function () use ($data, $productVariant) {
            $productVariant = ProductVariant::lockForUpdate()->findOrFail($productVariant->id);

            // Sin seguimiento de stock solo se puede sumar: el primer 'add'
            // activa el seguimiento y queda en el historial como 'initial'.
            $tracksStock = $productVariant->tracksStock();

            // Desactivar retira todo el stock actual (el historial guarda
            // cuánto tenía) y deja el producto sin seguimiento.
            if ($data['type'] === 'deactivate') {
                if (! $tracksStock) {
                    return response()->json([
                        'message' => 'Este producto ya no tiene seguimiento de stock.',
                    ], 422);
                }

                StockMovement::record($productVariant, 'subtract', $productVariant->stock_boxes, 'deactivated');

                return new ProductVariantResource($productVariant);
            }

            if (! $tracksStock && $data['type'] === 'subtract') {
                return response()->json([
                    'message' => 'Este producto no tiene seguimiento de stock activo.',
                ], 422);
            }

            // Un ajuste de 0 sobre stock ya controlado no cambia nada; solo la
            // activación del seguimiento puede empezar desde 0.
            if ($tracksStock && $data['quantity'] === 0) {
                return response()->json([
                    'message' => 'La cantidad debe ser de al menos 1.',
                    'errors' => ['quantity' => ['La cantidad debe ser de al menos 1.']],
                ], 422);
            }

            if ($data['type'] === 'subtract' && ! $productVariant->hasSufficientStock($data['quantity'])) {
                return response()->json([
                    'message' => "Stock insuficiente. Disponible: {$productVariant->stock_boxes} cajas.",
                ], 422);
            }

            StockMovement::record($productVariant, $data['type'], $data['quantity'], $tracksStock ? 'manual' : 'initial');

            return new ProductVariantResource($productVariant);
        });
    }
}
