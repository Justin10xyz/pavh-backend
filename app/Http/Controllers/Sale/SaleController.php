<?php

namespace App\Http\Controllers\Sale;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sale\StoreSaleRequest;
use App\Http\Resources\Sale\SaleResource;
use App\Models\ProductVariant;
use App\Models\Quote;
use App\Models\QuoteStatus;
use App\Models\Sale;
use App\Models\SimpleProduct;
use App\Services\DocumentPdfGenerator;
use App\Services\SaleFolioGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaleController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'customer_id' => ['nullable', 'integer'],
        ]);

        $allowedRelations = [
            'items' => ['items.productVariant', 'items.simpleProduct'],
            'customer' => 'customer',
        ];

        $with = collect(explode(',', $request->query('with', '')))
            ->map(fn ($relation) => trim($relation))
            ->filter(fn ($relation) => array_key_exists($relation, $allowedRelations))
            ->flatMap(fn ($relation) => (array) $allowedRelations[$relation])
            ->all();

        $sales = Sale::query()
            ->when($with !== [], fn ($query) => $query->with($with))
            ->dateRange($request->query('from'), $request->query('to'))
            ->forCustomer($request->integer('customer_id') ?: null)
            ->latest()
            ->get();

        return SaleResource::collection($sales);
    }

    public function show(Sale $sale)
    {
        // withTrashed: una venta ya cerrada se debe poder consultar aunque la
        // variante o su línea se hayan dado de baja después.
        $sale->load([
            'customer',
            'quote',
            'items.productVariant' => fn ($query) => $query->withTrashed(),
            'items.productVariant.product' => fn ($query) => $query->withTrashed(),
            'items.simpleProduct' => fn ($query) => $query->withTrashed(),
        ]);

        return new SaleResource($sale);
    }

    public function downloadPdf(Sale $sale, DocumentPdfGenerator $pdfGenerator)
    {
        // withTrashed: una venta ya cerrada se debe poder reimprimir aunque la
        // variante o su línea se hayan dado de baja después.
        $sale->load([
            'customer',
            'items.productVariant' => fn ($query) => $query->withTrashed(),
            'items.productVariant.product' => fn ($query) => $query->withTrashed(),
            'items.simpleProduct' => fn ($query) => $query->withTrashed(),
        ]);

        $pdf = $pdfGenerator->generate([
            'document_type' => 'Venta',
            'folio' => $sale->folio,
            'date' => $sale->created_at->format('d/m/Y'),
            'customer' => $sale->customer?->only(['name', 'phone', 'email']),
            'items' => $sale->items->map(fn ($item) => [
                // Producto simple: solo su nombre (no tiene línea/color/medida).
                'variant_label' => $item->simpleProduct
                    ? $item->simpleProduct->name
                    : trim(implode(' ', [
                        $item->productVariant->product->name,
                        $item->productVariant->color,
                        $item->productVariant->size,
                    ])),
                // Variantes se capturan en m², productos simples en unidades.
                'unit' => $item->simple_product_id ? 'uds' : 'm2',
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'line_total' => (float) $item->line_total,
            ])->all(),
            'subtotal' => (float) $sale->subtotal,
            'total' => (float) $sale->total,
        ]);

        return response()->streamDownload(
            fn () => print ($pdf),
            "Venta-{$sale->folio}.pdf",
            ['Content-Type' => 'application/pdf'],
        );
    }

    public function store(StoreSaleRequest $request, SaleFolioGenerator $folioGenerator)
    {
        $data = $request->validated();

        $quote = null;

        if (! empty($data['quote_id'])) {
            $quote = Quote::with('quoteStatus')->findOrFail($data['quote_id']);

            if ($message = $quote->conversionBlockedMessage()) {
                return response()->json(['message' => $message], 422);
            }
        }

        // La cantidad de cada línea está en m², pero el stock vive en cajas
        // (stock_boxes) — convertimos con m2_per_box y redondeamos hacia
        // arriba porque no se puede descontar una fracción de caja física.
        // El dinero (line_total) se calcula sobre la cantidad exacta en m²,
        // nunca sobre las cajas redondeadas.
        //
        // Los productos simples no tienen conversión: quantity ya está en la
        // misma unidad física que stock_quantity.
        $variants = [];
        $boxesNeededByVariant = [];
        $simpleProducts = [];
        $quantityNeededBySimpleProduct = [];

        foreach ($data['items'] as $item) {
            if (! empty($item['simple_product_id'])) {
                $simpleProductId = $item['simple_product_id'];
                $simpleProduct = $simpleProducts[$simpleProductId] ??= SimpleProduct::findOrFail($simpleProductId);

                // stock_quantity es entero; una cantidad fraccionaria no se puede descontar.
                if (floor((float) $item['quantity']) != (float) $item['quantity']) {
                    return response()->json([
                        'message' => "La cantidad de {$simpleProduct->name} debe ser un número entero de unidades.",
                    ], 422);
                }

                $quantityNeededBySimpleProduct[$simpleProductId] = ($quantityNeededBySimpleProduct[$simpleProductId] ?? 0) + (int) $item['quantity'];

                continue;
            }

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

        foreach ($quantityNeededBySimpleProduct as $simpleProductId => $quantityNeeded) {
            $simpleProduct = $simpleProducts[$simpleProductId];

            if (! $simpleProduct->hasSufficientStock($quantityNeeded)) {
                return response()->json([
                    'message' => "Stock insuficiente para {$simpleProduct->name}. Disponible: {$simpleProduct->stock_quantity} unidades, se requieren {$quantityNeeded}.",
                ], 422);
            }
        }

        $sale = DB::transaction(function () use ($data, $request, $folioGenerator, $variants, $boxesNeededByVariant, $simpleProducts, $quantityNeededBySimpleProduct, $quote) {
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
                    'product_variant_id' => $item['product_variant_id'] ?? null,
                    'simple_product_id' => $item['simple_product_id'] ?? null,
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'line_total' => $lineTotal,
                ]);

                $subtotal += $lineTotal;
            }

            foreach ($boxesNeededByVariant as $variantId => $boxesNeeded) {
                $variants[$variantId]->decrementStock($boxesNeeded);
            }

            foreach ($quantityNeededBySimpleProduct as $simpleProductId => $quantityNeeded) {
                $simpleProducts[$simpleProductId]->decrementStock($quantityNeeded);
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

        $sale->load(['items.productVariant', 'items.simpleProduct', 'customer', 'quote']);

        return new SaleResource($sale);
    }
}
