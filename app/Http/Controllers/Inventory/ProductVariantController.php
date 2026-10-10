<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\AdjustStockRequest;
use App\Http\Requests\Inventory\StoreProductVariantRequest;
use App\Http\Requests\Inventory\UpdateProductVariantRequest;
use App\Http\Resources\Inventory\ProductVariantResource;
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

        $variant = ProductVariant::create($data)->refresh();

        return new ProductVariantResource($variant);
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

    public function adjustStock(AdjustStockRequest $request, ProductVariant $productVariant)
    {
        $data = $request->validated();

        // Se relee con lockForUpdate() dentro de la transacción: el chequeo de
        // stock suficiente y el resulting_stock del historial deben ver el
        // stock real, no el de un ajuste/venta concurrente que aún no termina.
        return DB::transaction(function () use ($data, $productVariant) {
            $productVariant = ProductVariant::lockForUpdate()->findOrFail($productVariant->id);

            if ($data['type'] === 'subtract' && ! $productVariant->hasSufficientStock($data['quantity'])) {
                return response()->json([
                    'message' => "Stock insuficiente. Disponible: {$productVariant->stock_boxes} cajas.",
                ], 422);
            }

            StockMovement::record($productVariant, $data['type'], $data['quantity'], 'manual');

            return new ProductVariantResource($productVariant);
        });
    }
}
