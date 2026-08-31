<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSaleRequest;
use App\Http\Resources\SaleResource;
use App\Models\ProductVariant;
use App\Models\Quote;
use App\Models\QuoteStatus;
use App\Models\Sale;
use App\Services\SaleFolioGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaleController extends Controller
{
    public function index(Request $request)
    {
        $sales = Sale::query()
            ->when($request->query('with') === 'items', fn ($query) => $query->with('items.productVariant'))
            ->get();

        return SaleResource::collection($sales);
    }

    public function show(Sale $sale)
    {
        $sale->load(['items.productVariant', 'customer', 'quote']);

        return new SaleResource($sale);
    }

    public function store(StoreSaleRequest $request, SaleFolioGenerator $folioGenerator)
    {
        $data = $request->validated();

        $quote = null;

        if (! empty($data['quote_id'])) {
            $quote = Quote::with('quoteStatus')->findOrFail($data['quote_id']);

            if ($quote->quoteStatus->name === 'Convertida') {
                return response()->json([
                    'message' => 'Esta cotización ya fue convertida a venta.',
                ], 422);
            }
        }

        // La cantidad de cada línea está en m², pero el stock vive en cajas
        // (stock_boxes) — convertimos con m2_per_box y redondeamos hacia
        // arriba porque no se puede descontar una fracción de caja física.
        // El dinero (line_total) se calcula sobre la cantidad exacta en m²,
        // nunca sobre las cajas redondeadas.
        $variants = [];
        $boxesNeededByVariant = [];

        foreach ($data['items'] as $item) {
            $variantId = $item['product_variant_id'];
            $variant = $variants[$variantId] ??= ProductVariant::findOrFail($variantId);

            if ($variant->m2_per_box === null) {
                return response()->json([
                    'message' => "La variante {$variant->code} no tiene factor de conversión m2_per_box configurado, no se puede vender.",
                ], 422);
            }

            $boxesNeeded = (int) ceil($item['quantity'] / $variant->m2_per_box);

            $boxesNeededByVariant[$variantId] = ($boxesNeededByVariant[$variantId] ?? 0) + $boxesNeeded;
        }

        foreach ($boxesNeededByVariant as $variantId => $boxesNeeded) {
            $variant = $variants[$variantId];

            if (! $variant->hasSufficientStock($boxesNeeded)) {
                return response()->json([
                    'message' => "Stock insuficiente para {$variant->code}. Disponible: {$variant->stock_boxes} cajas, se requieren {$boxesNeeded}.",
                ], 422);
            }
        }

        $sale = DB::transaction(function () use ($data, $request, $folioGenerator, $variants, $boxesNeededByVariant, $quote) {
            $sale = Sale::create([
                'folio' => $folioGenerator->generate(),
                'quote_id' => $data['quote_id'] ?? null,
                'customer_id' => $data['customer_id'] ?? null,
                'subtotal' => 0,
                'total' => 0,
                'created_by' => $request->user()->id,
            ]);

            $subtotal = 0;

            foreach ($data['items'] as $item) {
                $lineTotal = round($item['quantity'] * $item['unit_price'], 2);

                $sale->items()->create([
                    'product_variant_id' => $item['product_variant_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'line_total' => $lineTotal,
                ]);

                $subtotal += $lineTotal;
            }

            foreach ($boxesNeededByVariant as $variantId => $boxesNeeded) {
                $variants[$variantId]->decrementStock($boxesNeeded);
            }

            // TODO: tax si aplica en el futuro
            $sale->update([
                'subtotal' => $subtotal,
                'total' => $subtotal,
            ]);

            if ($quote) {
                $convertedStatus = QuoteStatus::where('name', 'Convertida')->firstOrFail();

                $quote->update(['quote_status_id' => $convertedStatus->id]);
            }

            return $sale;
        });

        $sale->load(['items.productVariant', 'customer', 'quote']);

        return new SaleResource($sale);
    }
}
