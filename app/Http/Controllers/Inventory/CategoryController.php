<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreCategoryRequest;
use App\Http\Requests\Inventory\UpdateCategoryRequest;
use App\Http\Resources\Inventory\CategoryResource;
use App\Models\Category;

class CategoryController extends Controller
{
    public function index()
    {
        return CategoryResource::collection(Category::all());
    }

    public function store(StoreCategoryRequest $request)
    {
        $category = Category::create($request->validated())->refresh();

        return (new CategoryResource($category))->response()->setStatusCode(201);
    }

    public function update(UpdateCategoryRequest $request, Category $category)
    {
        $data = $request->validated();

        if (isset($data['product_form_type'])
            && $message = $category->productFormTypeChangeBlockedMessage($data['product_form_type'])) {
            return response()->json(['message' => $message], 422);
        }

        $category->update($data);

        return new CategoryResource($category);
    }

    // Soft delete (ver la migración de deleted_at). Se vuelve a validar aquí
    // aunque el frontend consulte antes delete-summary: entre una y otra
    // pudieron agregarse productos.
    public function destroy(Category $category)
    {
        if ($message = $category->deletionBlockedMessage()) {
            return response()->json(['message' => $message], 422);
        }

        $category->delete();

        return response()->noContent();
    }

    /**
     * Payload de solo lectura para el diálogo de borrado: con productos activos
     * el frontend muestra el motivo en vez de pedir confirmación.
     */
    public function deleteSummary(Category $category)
    {
        return response()->json([
            'data' => [
                'products_count' => $category->activeProductsCount(),
                'blocked_message' => $category->deletionBlockedMessage(),
            ],
        ]);
    }
}
