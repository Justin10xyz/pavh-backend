<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreSupplierRequest;
use App\Http\Requests\Inventory\UpdateSupplierRequest;
use App\Http\Resources\Inventory\SupplierResource;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class SupplierController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return SupplierResource::collection(Supplier::all());
    }

    public function store(StoreSupplierRequest $request): JsonResponse
    {
        $supplier = Supplier::create($request->validated());

        return (new SupplierResource($supplier))->response()->setStatusCode(201);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): SupplierResource
    {
        $supplier->update($request->validated());

        return new SupplierResource($supplier);
    }

    // Soft delete (ver la migración de deleted_at). Se vuelve a validar aquí
    // aunque el frontend consulte antes delete-summary: entre una y otra
    // pudieron agregarse productos.
    public function destroy(Supplier $supplier): JsonResponse|Response
    {
        if ($message = $supplier->deletionBlockedMessage()) {
            return response()->json(['message' => $message], 422);
        }

        $supplier->delete();

        return response()->noContent();
    }

    /**
     * Payload de solo lectura para el diálogo de borrado: con productos activos
     * el frontend muestra el motivo en vez de pedir confirmación.
     */
    public function deleteSummary(Supplier $supplier): JsonResponse
    {
        return response()->json([
            'data' => [
                'products_count' => $supplier->activeProductsCount(),
                'blocked_message' => $supplier->deletionBlockedMessage(),
            ],
        ]);
    }
}
