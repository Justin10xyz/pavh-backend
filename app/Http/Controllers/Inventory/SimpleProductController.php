<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\AdjustStockRequest;
use App\Http\Requests\Inventory\StoreSimpleProductRequest;
use App\Http\Requests\Inventory\UpdateSimpleProductRequest;
use App\Http\Resources\Inventory\SimpleProductResource;
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

        $simpleProduct = SimpleProduct::create($data)->refresh();

        return new SimpleProductResource($simpleProduct);
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

    public function adjustStock(AdjustStockRequest $request, SimpleProduct $simpleProduct)
    {
        $data = $request->validated();

        // Se relee con lockForUpdate() dentro de la transacción: el chequeo de
        // stock suficiente y el resulting_stock del historial deben ver el
        // stock real, no el de un ajuste/venta concurrente que aún no termina.
        return DB::transaction(function () use ($data, $simpleProduct) {
            $simpleProduct = SimpleProduct::lockForUpdate()->findOrFail($simpleProduct->id);

            if ($data['type'] === 'subtract' && ! $simpleProduct->hasSufficientStock($data['quantity'])) {
                return response()->json([
                    'message' => "Stock insuficiente. Disponible: {$simpleProduct->stock_quantity} unidades.",
                ], 422);
            }

            StockMovement::record($simpleProduct, $data['type'], $data['quantity'], 'manual');

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
