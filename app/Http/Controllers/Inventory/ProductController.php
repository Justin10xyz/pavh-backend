<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreProductRequest;
use App\Http\Requests\Inventory\UpdateProductRequest;
use App\Http\Resources\Inventory\ProductResource;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $products = Product::query()
            ->when($request->query('with') === 'variants', fn ($query) => $query->with('variants'))
            ->get();

        return ProductResource::collection($products);
    }

    public function show(Request $request, Product $product)
    {
        if ($request->query('with') === 'variants') {
            $product->load('variants.commissionCategory');
        }

        return new ProductResource($product);
    }

    public function store(StoreProductRequest $request)
    {
        $product = Product::create($request->validated());

        return new ProductResource($product);
    }

    public function update(UpdateProductRequest $request, Product $product)
    {
        $data = $request->validated();

        if (isset($data['unit_type_id'])
            && $message = $product->unitTypeChangeBlockedMessage((int) $data['unit_type_id'])) {
            return response()->json(['message' => $message], 422);
        }

        $product->update($data);

        return new ProductResource($product);
    }

    public function destroy(Product $product)
    {
        $product->delete();

        return response()->noContent();
    }
}
