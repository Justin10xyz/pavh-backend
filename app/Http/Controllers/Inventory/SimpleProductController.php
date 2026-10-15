<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\AdjustStockRequest;
use App\Http\Requests\Inventory\StoreSimpleProductRequest;
use App\Http\Requests\Inventory\UpdateSimpleProductRequest;
use App\Http\Resources\Inventory\SimpleProductResource;
use App\Http\Resources\Inventory\StockMovementResource;
use App\Models\Category;
use App\Models\SimpleProduct;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

class SimpleProductController extends Controller
{
    public function index()
    {
        return SimpleProductResource::collection(SimpleProduct::all());
    }

    public function show(SimpleProduct $simpleProduct)
    {
        return new SimpleProductResource($simpleProduct);
    }

    public function store(StoreSimpleProductRequest $request)
    {
        $data = $request->validated();

        if (! Category::findOrFail($data['category_id'])->usesSimpleProductForm()) {
            return $this->wrongCategoryTypeResponse();
        }

        // El stock inicial no se guarda directo: entra como movimiento
        // 'initial' para que quede en el historial. Sin stock_quantity el
        // producto se crea sin seguimiento.
        $initialStock = $data['stock_quantity'] ?? null;
        unset($data['stock_quantity']);

        $simpleProduct = DB::transaction(function () use ($data, $initialStock) {
            $simpleProduct = SimpleProduct::create($data);

            if ($initialStock !== null) {
                StockMovement::record($simpleProduct, 'add', $initialStock, 'initial');
            }

            return $simpleProduct;
        });

        return new SimpleProductResource($simpleProduct->refresh());
    }

    public function update(UpdateSimpleProductRequest $request, SimpleProduct $simpleProduct)
    {
        $data = $request->validated();

        if (isset($data['category_id']) && ! Category::findOrFail($data['category_id'])->usesSimpleProductForm()) {
            return $this->wrongCategoryTypeResponse();
        }

        $simpleProduct->update($data);

        return new SimpleProductResource($simpleProduct);
    }

    public function destroy(SimpleProduct $simpleProduct)
    {
        $simpleProduct->delete();

        return response()->noContent();
    }

    /**
     * Historial completo, sin paginar (mismo criterio que los demás listados).
     * La venta se carga con withTrashed() para conservar el folio aunque la
     * venta se haya dado de baja.
     */
    public function stockMovements(SimpleProduct $simpleProduct)
    {
        $movements = $simpleProduct->stockMovements()
            ->with(['sale' => fn ($query) => $query->withTrashed()])
            ->latest()
            ->latest('id')
            ->get();

        return StockMovementResource::collection($movements);
    }

    public function adjustStock(AdjustStockRequest $request, SimpleProduct $simpleProduct)
    {
        $data = $request->validated();

        // Se relee con lockForUpdate() dentro de la transacción: el chequeo de
        // stock suficiente y el resulting_stock del historial deben ver el
        // stock real, no el de un ajuste/venta concurrente que aún no termina.
        return DB::transaction(function () use ($data, $simpleProduct) {
            $simpleProduct = SimpleProduct::lockForUpdate()->findOrFail($simpleProduct->id);

            // Sin seguimiento de stock solo se puede sumar: el primer 'add'
            // activa el seguimiento y queda en el historial como 'initial'.
            $tracksStock = $simpleProduct->tracksStock();

            // Desactivar retira todo el stock actual (el historial guarda
            // cuánto tenía) y deja el producto sin seguimiento.
            if ($data['type'] === 'deactivate') {
                if (! $tracksStock) {
                    return response()->json([
                        'message' => 'Este producto ya no tiene seguimiento de stock.',
                    ], 422);
                }

                StockMovement::record($simpleProduct, 'subtract', $simpleProduct->stock_quantity, 'deactivated');

                return new SimpleProductResource($simpleProduct);
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

            if ($data['type'] === 'subtract' && ! $simpleProduct->hasSufficientStock($data['quantity'])) {
                return response()->json([
                    'message' => "Stock insuficiente. Disponible: {$simpleProduct->stock_quantity} unidades.",
                ], 422);
            }

            StockMovement::record($simpleProduct, $data['type'], $data['quantity'], $tracksStock ? 'manual' : 'initial');

            return new SimpleProductResource($simpleProduct);
        });
    }

    private function wrongCategoryTypeResponse()
    {
        return response()->json([
            'message' => 'La categoría seleccionada no admite productos simples.',
        ], 422);
    }
}
