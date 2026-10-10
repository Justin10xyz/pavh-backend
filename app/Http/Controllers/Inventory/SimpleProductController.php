<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreSimpleProductRequest;
use App\Http\Requests\Inventory\UpdateSimpleProductRequest;
use App\Http\Resources\Inventory\SimpleProductResource;
use App\Models\Category;
use App\Models\SimpleProduct;

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

    private function wrongCategoryTypeResponse()
    {
        return response()->json([
            'message' => 'La categoría seleccionada no admite productos simples.',
        ], 422);
    }
}
