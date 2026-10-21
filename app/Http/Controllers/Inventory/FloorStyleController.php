<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreFloorStyleRequest;
use App\Http\Requests\Inventory\UpdateFloorStyleRequest;
use App\Http\Resources\Inventory\FloorStyleResource;
use App\Models\FloorStyle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class FloorStyleController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return FloorStyleResource::collection(FloorStyle::all());
    }

    public function store(StoreFloorStyleRequest $request): JsonResponse
    {
        $floorStyle = FloorStyle::create($request->validated());

        return (new FloorStyleResource($floorStyle))->response()->setStatusCode(201);
    }

    public function update(UpdateFloorStyleRequest $request, FloorStyle $floorStyle): FloorStyleResource
    {
        $floorStyle->update($request->validated());

        return new FloorStyleResource($floorStyle);
    }

    // Soft delete (ver la migración de floor_styles). Se vuelve a validar aquí
    // aunque el frontend consulte antes delete-summary: entre una y otra
    // pudieron agregarse productos.
    public function destroy(FloorStyle $floorStyle): JsonResponse|Response
    {
        if ($message = $floorStyle->deletionBlockedMessage()) {
            return response()->json(['message' => $message], 422);
        }

        $floorStyle->delete();

        return response()->noContent();
    }

    /**
     * Payload de solo lectura para el diálogo de borrado: con productos activos
     * el frontend muestra el motivo en vez de pedir confirmación.
     */
    public function deleteSummary(FloorStyle $floorStyle): JsonResponse
    {
        return response()->json([
            'data' => [
                'products_count' => $floorStyle->activeProductsCount(),
                'blocked_message' => $floorStyle->deletionBlockedMessage(),
            ],
        ]);
    }
}
