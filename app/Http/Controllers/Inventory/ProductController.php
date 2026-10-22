<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreProductRequest;
use App\Http\Requests\Inventory\UpdateProductRequest;
use App\Http\Resources\Inventory\ProductResource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quote;
use App\Models\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

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

    /**
     * Soft delete no bloqueante, como Clientes: el piso se puede borrar siempre,
     * tenga o no historial. Primero se dan de baja sus variantes activas (igual
     * que DELETE /product-variants/{id}, con delete() sobre cada modelo) y después
     * el piso; cotizaciones y ventas los siguen viendo con withTrashed().
     */
    public function destroy(Product $product): Response
    {
        DB::transaction(function () use ($product) {
            $product->variants->each(fn (ProductVariant $variant) => $variant->delete());

            $product->delete();
        });

        return response()->noContent();
    }

    /**
     * Payload de solo lectura para el ConfirmDialog de borrado, mismo contrato
     * que GET /api/customers/{id}/delete-summary. Es informativo: DELETE
     * /api/products/{product} no se bloquea aunque haya registros asociados.
     * Cuenta cotizaciones/ventas distintas con al menos una línea de alguna
     * variante del piso, incluidas las variantes ya dadas de baja una por una.
     */
    public function deleteSummary(Product $product): JsonResponse
    {
        $variantIds = $product->variants()->withTrashed()->select('id');

        $referencesVariants = fn ($query) => $query->whereIn('product_variant_id', $variantIds);

        return response()->json([
            'data' => [
                'quotes_count' => Quote::whereHas('items', $referencesVariants)->count(),
                'sales_count' => Sale::whereHas('items', $referencesVariants)->count(),
            ],
        ]);
    }
}
