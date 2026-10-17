<?php

namespace App\Http\Controllers\Quote;

use App\Http\Controllers\Controller;
use App\Http\Requests\Quote\StoreQuoteRequest;
use App\Http\Requests\Quote\UpdateQuoteRequest;
use App\Http\Resources\Quote\QuoteResource;
use App\Models\Customer;
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
            'items.productVariant.product.unitType',
            'items.simpleProduct' => fn ($query) => $query->withTrashed(),
            'customer',
            'quoteStatus',
            // withTrashed: el detalle debe seguir indicando a qué venta se
            // convirtió aunque esa venta se dé de baja después.
            'sale' => fn ($query) => $query->withTrashed(),
        ]);

        return new QuoteResource($quote);
    }

    public function store(StoreQuoteRequest $request, QuoteFolioGenerator $folioGenerator)
    {
        $data = $request->validated();

        if ($message = $this->lineRejectionMessage($data['items'])) {
            return response()->json(['message' => $message], 422);
        }

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

        // Solo se rechaza un cliente borrado si es un cambio; conservar el
        // cliente actual aunque ya esté borrado no bloquea la edición.
        $customerId = $data['customer_id'] ?? null;

        if ($customerId !== null
            && (int) $customerId !== (int) $quote->customer_id
            && Customer::onlyTrashed()->whereKey($customerId)->exists()) {
            return response()->json([
                'message' => 'El cliente seleccionado ya no está disponible.',
            ], 422);
        }

        if ($message = $this->lineRejectionMessage($data['items'])) {
            return response()->json(['message' => $message], 422);
        }

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
     * Solo cambia el estado; no toca stock ni ventas.
     */
    public function cancel(Quote $quote)
    {
        if ($message = $quote->cancellationBlockedMessage()) {
            return response()->json(['message' => $message], 422);
        }

        $cancelledStatus = QuoteStatus::where('name', 'Cancelada')->firstOrFail();

        $quote->update(['quote_status_id' => $cancelledStatus->id]);

        $quote->load(['items.productVariant', 'items.simpleProduct', 'customer', 'quoteStatus']);

        return new QuoteResource($quote);
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

        // Se respeta el price_type de la cotización, pero el precio se
        // re-resuelve contra el actual de ese tipo: si al producto le quitaron
        // el mayoreo desde que se cotizó, se rechaza igual que al cotizar.
        $missingPriceItems = $quote->items->filter(
            fn ($item) => $item->simpleProduct?->missingPriceMessage($item->price_type) !== null
        );

        if ($missingPriceItems->isNotEmpty()) {
            $labels = $missingPriceItems->map(fn ($item) => $item->simpleProduct->name)->unique()->implode(', ');

            return response()->json([
                'message' => "No se puede convertir la cotización: los siguientes productos no tienen precio de mayoreo configurado: {$labels}.",
            ], 422);
        }

        return response()->json([
            'data' => [
                'quote_id' => $quote->id,
                'customer_id' => $quote->customer_id,
                'notes' => $quote->notes,
                // Variantes: `boxes` es lo que se manda a POST /sales; `quantity`
                // (m²) va solo como referencia. Simples: `quantity` en unidades.
                'items' => $quote->items->map(fn ($item) => [
                    'product_variant_id' => $item->product_variant_id,
                    'simple_product_id' => $item->simple_product_id,
                    'boxes' => $item->boxes,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->simpleProduct?->priceFor($item->price_type)
                        ?? ($item->productVariant->isSoldByPiece() ? $item->productVariant->price_per_box : $item->productVariant->price_per_m2),
                    // Solo productos simples; en variantes POST /sales lo prohíbe.
                    'price_type' => $item->simple_product_id ? $item->price_type : null,
                ]),
            ],
        ]);
    }

    public function downloadPdf(Quote $quote, DocumentPdfGenerator $pdfGenerator)
    {
        // withTrashed: una cotización vieja se debe poder reimprimir aunque la
        // variante o su línea se hayan dado de baja después.
        $quote->load([
            'customer',
            'items.productVariant' => fn ($query) => $query->withTrashed(),
            'items.productVariant.product' => fn ($query) => $query->withTrashed(),
            'items.productVariant.product.unitType',
            'items.simpleProduct' => fn ($query) => $query->withTrashed(),
        ]);

        $pdf = $pdfGenerator->generate([
            'document_type' => 'Cotización',
            'folio' => $quote->folio,
            'date' => $quote->created_at->format('d/m/Y'),
            'customer' => $quote->customer?->only(['name', 'phone', 'email', 'address', 'neighborhood', 'city']),
            'items' => $quote->items->map(fn ($item) => [
                // Producto simple: solo su nombre (no tiene línea/color/medida).
                'variant_label' => $item->simpleProduct
                    ? $item->simpleProduct->name
                    : trim(implode(' ', [
                        $item->productVariant->product->name,
                        $item->productVariant->color,
                        $item->productVariant->size,
                    ])),
                // Variantes por caja (con los m² que cubren); productos simples y
                // variantes por pieza en unidades.
                'unit' => $item->simple_product_id || $item->productVariant->isSoldByPiece() ? 'uds' : 'm2',
                'boxes' => $item->boxes,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'price_type' => $item->price_type,
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

    /**
     * Va antes de la transacción (no dentro de syncItems) para rechazar sin
     * haber creado la cotización ni borrado las líneas existentes en update.
     *
     * - Producto simple: cantidad fraccionaria, o línea de mayoreo sin
     *   price_wholesale configurado (nunca cae al precio de menudeo).
     * - Variante sin m2_per_box: no hay forma de derivar los m² que cubren las
     *   cajas, mismo rechazo que en SaleController@store (nunca 1:1). No
     *   aplica a variantes por pieza, que no derivan m².
     */
    private function lineRejectionMessage(array $items): ?string
    {
        foreach ($items as $item) {
            if (! empty($item['simple_product_id'])) {
                $simpleProduct = SimpleProduct::findOrFail($item['simple_product_id']);
                $message = $simpleProduct->fractionalQuantityMessage($item['quantity'])
                    ?? $simpleProduct->missingPriceMessage($item['price_type'] ?? 'retail');

                if ($message) {
                    return $message;
                }

                continue;
            }

            $variant = ProductVariant::findOrFail($item['product_variant_id']);

            if (! $variant->isSoldByPiece() && $variant->m2_per_box === null) {
                return "La variante {$variant->code} no tiene factor de conversión m2_per_box configurado, no se puede cotizar.";
            }
        }

        return null;
    }

    private function syncItems(Quote $quote, array $items): void
    {
        $subtotal = 0;

        foreach ($items as $item) {
            // Precio siempre resuelto del catálogo actual, nunca del cliente.
            // Variantes por caja completa: se cotizan los m² que cubren las
            // cajas (boxes × m2_per_box), mismo criterio que la venta.
            // Variantes por pieza: `boxes` son piezas, cobradas a price_per_box.
            // Productos simples: menudeo o mayoreo según `price_type`; las
            // variantes no tienen mayoreo y siempre quedan en retail.
            $priceType = $item['price_type'] ?? 'retail';

            if (! empty($item['simple_product_id'])) {
                $unitPrice = SimpleProduct::findOrFail($item['simple_product_id'])->priceFor($priceType);
                $boxes = null;
                $quantity = $item['quantity'];
            } else {
                $variant = ProductVariant::findOrFail($item['product_variant_id']);
                $boxes = (int) $item['boxes'];

                if ($variant->isSoldByPiece()) {
                    $unitPrice = $variant->price_per_box;
                    $quantity = $boxes;
                } else {
                    $unitPrice = $variant->price_per_m2;
                    $quantity = $variant->squareMetersForBoxes($boxes);
                }
            }

            $lineTotal = round($quantity * $unitPrice, 2);

            $quote->items()->create([
                'product_variant_id' => $item['product_variant_id'] ?? null,
                'simple_product_id' => $item['simple_product_id'] ?? null,
                'boxes' => $boxes,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'price_type' => $priceType,
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
