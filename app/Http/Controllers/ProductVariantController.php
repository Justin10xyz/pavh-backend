<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdjustStockRequest;
use App\Http\Requests\StoreProductVariantRequest;
use App\Http\Requests\UpdateProductVariantRequest;
use App\Http\Resources\ProductVariantResource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\VariantCodeGenerator;
use Illuminate\Http\Request;

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

        if ($data['type'] === 'subtract' && $data['quantity'] > $productVariant->stock_boxes) {
            return response()->json([
                'message' => "Stock insuficiente. Disponible: {$productVariant->stock_boxes} cajas.",
            ], 422);
        }

        $delta = $data['type'] === 'add' ? $data['quantity'] : -$data['quantity'];

        $productVariant->increment('stock_boxes', $delta);

        return new ProductVariantResource($productVariant);
    }
}
