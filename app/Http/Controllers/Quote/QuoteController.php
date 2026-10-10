<?php

namespace App\Http\Controllers\Quote;

use App\Http\Controllers\Controller;
use App\Http\Requests\Quote\StoreQuoteRequest;
use App\Http\Requests\Quote\UpdateQuoteRequest;
use App\Http\Resources\Quote\QuoteResource;
use App\Models\ProductVariant;
use App\Models\Quote;
use App\Models\QuoteStatus;
use App\Models\SimpleProduct;

use App\Services\DocumentPdfGenerator;
use App\Services\QuoteFolioGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuoteController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'customer_id' => ['nullable', 'integer'],
        ]);

        $allowedRelations = [
            'items' => ['items.productVariant', 'items.simpleProduct'],
            'customer' => 'customer',
            'quoteStatus' => 'quoteStatus',
        ];

        $with = collect(explode(',', $request->query('with', '')))
            ->map(fn ($relation) => trim($relation))
            ->filter(fn ($relation) => array_key_exists($relation, $allowedRelations))
            ->flatMap(fn ($relation) => (array) $allowedRelations[$relation])
            ->all();

        $quotes = Quote::query()
            ->when($with !== [], fn ($query) => $query->with($with))
            ->forCustomer($request->integer('customer_id') ?: null)
            ->latest()
            ->get();

        return QuoteResource::collection($quotes);
    }

    public function show(Quote $quote)
    {
        $quote->load([
            'items.productVariant',
            'items.productVariant.product',
            'items.simpleProduct' => fn ($query) => $query->withTrashed(),
            'customer',
            'quoteStatus',
        ]);

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

        $quote->load(['items.productVariant', 'items.simpleProduct', 'customer', 'quoteStatus']);

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

        $quote->load(['items.productVariant', 'items.simpleProduct', 'customer', 'quoteStatus']);

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
        if ($message = $quote->conversionBlockedMessage()) {
            return response()->json(['message' => $message], 422);
        }

        $quote->load(['items.productVariant', 'items.simpleProduct']);

        $unavailableVariantItems = $quote->items->filter(
            fn ($item) => $item->product_variant_id !== null && $item->productVariant === null
        );
        $unavailableSimpleProductItems = $quote->items->filter(
            fn ($item) => $item->simple_product_id !== null && $item->simpleProduct === null
        );

        if ($unavailableVariantItems->isNotEmpty() || $unavailableSimpleProductItems->isNotEmpty()) {
            $reasons = [];

            if ($unavailableVariantItems->isNotEmpty()) {
                // withTrashed() solo para poder nombrar la variante en el mensaje; la conversión no continúa.
                $trashedVariants = ProductVariant::withTrashed()
                    ->whereIn('id', $unavailableVariantItems->pluck('product_variant_id'))
                    ->get()
                    ->keyBy('id');

                $labels = $unavailableVariantItems->map(function ($item) use ($trashedVariants) {
                    $variant = $trashedVariants->get($item->product_variant_id);

                    return $variant?->code ?? "ID {$item->product_variant_id}";
                })->unique()->implode(', ');

                $reasons[] = "las siguientes variantes ya no están disponibles: {$labels}";
            }

            if ($unavailableSimpleProductItems->isNotEmpty()) {
                // withTrashed() solo para poder nombrar el producto en el mensaje; la conversión no continúa.
                $trashedSimpleProducts = SimpleProduct::withTrashed()
                    ->whereIn('id', $unavailableSimpleProductItems->pluck('simple_product_id'))
                    ->get()
                    ->keyBy('id');

                $labels = $unavailableSimpleProductItems->map(function ($item) use ($trashedSimpleProducts) {
                    $simpleProduct = $trashedSimpleProducts->get($item->simple_product_id);

                    return $simpleProduct?->name ?? "ID {$item->simple_product_id}";
                })->unique()->implode(', ');

                $reasons[] = "los siguientes productos ya no están disponibles: {$labels}";
            }

            return response()->json([
                'message' => 'No se puede convertir la cotización: '.implode('; ', $reasons).'.',
            ], 422);
        }

        return response()->json([
            'data' => [
                'quote_id' => $quote->id,
                'customer_id' => $quote->customer_id,
                'notes' => $quote->notes,
                'items' => $quote->items->map(fn ($item) => [
                    'product_variant_id' => $item->product_variant_id,
                    'simple_product_id' => $item->simple_product_id,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->simpleProduct?->price ?? $item->productVariant->price_per_m2,
                ]),
            ],
        ]);
    }

    public function downloadPdf(Quote $quote, DocumentPdfGenerator $pdfGenerator){
        // withTrashed: una cotización vieja se debe poder reimprimir aunque la
        // variante o su línea se hayan dado de baja después.
        $quote->load([
            'customer',
            'items.productVariant' => fn ($query) => $query->withTrashed(),
            'items.productVariant.product' => fn ($query) => $query->withTrashed(),
            'items.simpleProduct' => fn ($query) => $query->withTrashed(),
        ]);

        $pdf = $pdfGenerator->generate([
            'document_type' => 'Cotización',
            'folio' => $quote->folio,
            'date' => $quote->created_at->format('d/m/Y'),
            'customer' => $quote->customer?->only(['name', 'phone', 'email']),
            'items' => $quote->items->map(fn ($item) => [
                // Producto simple: solo su nombre (no tiene línea/color/medida).
                'variant_label' => $item->simpleProduct
                    ? $item->simpleProduct->name
                    : trim(implode(' ', [
                        $item->productVariant->product->name,
                        $item->productVariant->color,
                        $item->productVariant->size,
                    ])),
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'line_total' => (float) $item->line_total,
            ])->all(),
            'subtotal' => (float) $quote->subtotal,
            'total' => (float) $quote->total,
        ]);

        return response()->streamDownload(
            fn () => print ($pdf),
            "Cotizacion-{$quote->folio}.pdf",
            ['Content-Type' => 'application/pdf'],
        );
    }

    private function syncItems(Quote $quote, array $items): void
    {
        $subtotal = 0;

        foreach ($items as $item) {
            // Precio siempre resuelto del catálogo actual, nunca del cliente.
            if (! empty($item['simple_product_id'])) {
                $unitPrice = SimpleProduct::findOrFail($item['simple_product_id'])->price;
            } else {
                $unitPrice = ProductVariant::findOrFail($item['product_variant_id'])->price_per_m2;
            }

            $lineTotal = round($item['quantity'] * $unitPrice, 2);

            $quote->items()->create([
                'product_variant_id' => $item['product_variant_id'] ?? null,
                'simple_product_id' => $item['simple_product_id'] ?? null,
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
