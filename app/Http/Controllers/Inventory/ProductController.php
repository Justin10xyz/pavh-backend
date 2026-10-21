<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreProductRequest;
use App\Http\Requests\Inventory\UpdateProductRequest;
use App\Http\Resources\Inventory\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ProductController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $products = Product::query()
            // Mismo eager loading que show(): sin `commissionCategory`, el listado de
            // Inventario no recibía el código de comisión (ProductVariantResource usa whenLoaded).
            ->when($request->query('with') === 'variants', fn ($query) => $query->with('variants.commissionCategory'))
            ->get();

        return ProductResource::collection($products);
    }

    public function show(Request $request, Product $product): ProductResource
    {
        if ($request->query('with') === 'variants') {
            $product->load('variants.commissionCategory');
        }

        return new ProductResource($product);
    }

    public function store(StoreProductRequest $request): ProductResource
    {
        $product = Product::create($request->validated());

        return new ProductResource($product);
    }

    public function update(UpdateProductRequest $request, Product $product): ProductResource|JsonResponse
    {
        $data = $request->validated();

        if (isset($data['unit_type_id'])
            && $message = $product->unitTypeChangeBlockedMessage((int) $data['unit_type_id'])) {
            return response()->json(['message' => $message], 422);
        }

        $product->update($data);

        return new ProductResource($product);
    }

    public function destroy(Product $product): Response
    {
        $product->delete();

        return response()->noContent();
    }
}
