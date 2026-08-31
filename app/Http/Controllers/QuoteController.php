<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreQuoteRequest;
use App\Http\Requests\UpdateQuoteRequest;
use App\Http\Resources\QuoteResource;
use App\Models\ProductVariant;
use App\Models\Quote;
use App\Models\QuoteStatus;
use App\Services\QuoteFolioGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuoteController extends Controller
{
    public function index(Request $request)
    {
        $allowedRelations = [
            'items' => 'items.productVariant',
            'customer' => 'customer',
            'quoteStatus' => 'quoteStatus',
        ];

        $with = collect(explode(',', $request->query('with', '')))
            ->map(fn ($relation) => trim($relation))
            ->filter(fn ($relation) => array_key_exists($relation, $allowedRelations))
            ->map(fn ($relation) => $allowedRelations[$relation])
            ->all();

        $quotes = Quote::query()
            ->when($with !== [], fn ($query) => $query->with($with))
            ->get();

        return QuoteResource::collection($quotes);
    }

    public function show(Quote $quote)
    {
        $quote->load(['items.productVariant', 'customer', 'quoteStatus']);

        return new QuoteResource($quote);
    }

    public function store(StoreQuoteRequest $request, QuoteFolioGenerator $folioGenerator)
    {
        $data = $request->validated();

        $quote = DB::transaction(function () use ($data, $request, $folioGenerator) {
            $draftStatus = QuoteStatus::where('name', 'Borrador')->firstOrFail();

            $data['folio'] = $folioGenerator->generate();
            $data['quote_status_id'] = $draftStatus->id;
            $data['subtotal'] = 0;
            $data['total'] = 0;
            $data['created_by'] = $request->user()->id;

            $quote = Quote::create($data);

            $this->syncItems($quote, $data['items']);

            return $quote;
        });

        $quote->load(['items.productVariant', 'customer', 'quoteStatus']);

        return new QuoteResource($quote);
    }

    public function update(UpdateQuoteRequest $request, Quote $quote)
    {
        if ($quote->quoteStatus->name !== 'Borrador') {
            return response()->json([
                'message' => 'Solo se pueden editar cotizaciones en estado Borrador.',
            ], 422);
        }

        $data = $request->validated();

        DB::transaction(function () use ($quote, $data) {
            $quote->update($data);

            $quote->items()->delete();

            $this->syncItems($quote, $data['items']);
        });

        $quote->load(['items.productVariant', 'customer', 'quoteStatus']);

        return new QuoteResource($quote);
    }

    public function destroy(Quote $quote)
    {
        $quote->items()->delete();
        $quote->delete();

        return response()->noContent();
    }

    /**
     * Payload de solo lectura para prellenar el form de nueva venta. No muta
     * nada — la conversión real ocurre al confirmar POST /api/sales con
     * quote_id, que marca la cotización como Convertida.
     */
    public function convert(Quote $quote)
    {
        if ($quote->quoteStatus->name === 'Convertida') {
            return response()->json([
                'message' => 'Esta cotización ya fue convertida a venta.',
            ], 422);
        }

        $quote->load('items.productVariant');

        return response()->json([
            'data' => [
                'quote_id' => $quote->id,
                'customer_id' => $quote->customer_id,
                'notes' => $quote->notes,
                'items' => $quote->items->map(fn ($item) => [
                    'product_variant_id' => $item->product_variant_id,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->productVariant->price_per_m2,
                ]),
            ],
        ]);
    }

    private function syncItems(Quote $quote, array $items): void
    {
        $subtotal = 0;

        foreach ($items as $item) {
            $variant = ProductVariant::findOrFail($item['product_variant_id']);
            $unitPrice = $variant->price_per_m2;
            $lineTotal = round($item['quantity'] * $unitPrice, 2);

            $quote->items()->create([
                'product_variant_id' => $variant->id,
                'quantity' => $item['quantity'],
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
            ]);

            $subtotal += $lineTotal;
        }

        // TODO: tax si aplica en el futuro
        $quote->update([
            'subtotal' => $subtotal,
            'total' => $subtotal,
        ]);
    }
}
