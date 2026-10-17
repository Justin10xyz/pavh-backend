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
use App\Models\StockMovement;
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
            'quote' => 'quote',
        ];

        $with = collect(explode(',', $request->query('with', '')))
            ->map(fn ($relation) => trim($relation))
            ->filter(fn ($relation) => array_key_exists($relation, $allowedRelations))
            ->flatMap(fn ($relation) => (array) $allowedRelations[$relation])
            ->all();

        $sales = Sale::query()
            ->with('customer')
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
            'items.productVariant.product.unitType',
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
            'items.productVariant.product.unitType',
            'items.simpleProduct' => fn ($query) => $query->withTrashed(),
        ]);

        $pdf = $pdfGenerator->generate([
            'document_type' => 'Venta',
            'folio' => $sale->folio,
            'date' => $sale->created_at->format('d/m/Y'),
            'customer' => $sale->customer?->only(['name', 'phone', 'email', 'address', 'neighborhood', 'city']),
            'items' => $sale->items->map(fn ($item) => [
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

        // Las variantes se venden por caja completa: la línea trae `boxes`, que
        // es lo que se descuenta de stock_boxes, y sus m² (`quantity`) se
        // derivan aquí como boxes × m2_per_box. El dinero se calcula sobre esos
        // m² — los que realmente se entregan —, no sobre los que pidió el
        // cliente. Por eso cada línea se normaliza en $lines antes de crear nada.
        //
        // Los productos simples no tienen conversión: quantity ya está en la
        // misma unidad física que stock_quantity.
        $lines = [];
        $variants = [];
        $boxesNeededByVariant = [];
        $simpleProducts = [];
        $quantityNeededBySimpleProduct = [];

        foreach ($data['items'] as $item) {
            if (! empty($item['simple_product_id'])) {
                $simpleProductId = $item['simple_product_id'];
                $simpleProduct = $simpleProducts[$simpleProductId] ??= SimpleProduct::findOrFail($simpleProductId);

                if ($message = $simpleProduct->fractionalQuantityMessage($item['quantity'])) {
                    return response()->json(['message' => $message], 422);
                }

                $quantityNeededBySimpleProduct[$simpleProductId] = ($quantityNeededBySimpleProduct[$simpleProductId] ?? 0) + (int) $item['quantity'];

                // price_type se guarda tal cual: unit_price ya lo decidió el
                // vendedor y no se compara contra price/price_wholesale.
                $lines[] = [
                    'simple_product_id' => $simpleProductId,
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'price_type' => $item['price_type'] ?? 'retail',
                ];

                continue;
            }

            $variantId = $item['product_variant_id'];
            $variant = $variants[$variantId] ??= ProductVariant::findOrFail($variantId);

            // Variantes por pieza: `boxes` son piezas y quantity es esa misma
            // cantidad, sin derivar m².
            $soldByPiece = $variant->isSoldByPiece();

            if (! $soldByPiece && $variant->m2_per_box === null) {
                return response()->json([
                    'message' => "La variante {$variant->code} no tiene factor de conversión m2_per_box configurado, no se puede vender.",
                ], 422);
            }

            $boxes = (int) $item['boxes'];

            $boxesNeededByVariant[$variantId] = ($boxesNeededByVariant[$variantId] ?? 0) + $boxes;

            $lines[] = [
                'product_variant_id' => $variantId,
                'boxes' => $boxes,
                'quantity' => $soldByPiece ? $boxes : $variant->squareMetersForBoxes($boxes),
                'unit_price' => $item['unit_price'],
            ];
        }

        // El chequeo de stock suficiente y el descuento leen cada producto con
        // lockForUpdate() dentro de la misma transacción: dos ventas
        // concurrentes sobre el mismo producto se serializan aquí, así que no
        // pueden ambas pasar la validación y dejar el stock negativo. Se
        // bloquea en orden de id para que dos ventas con los mismos productos
        // no se crucen en deadlock.
        //
        // La cotización se bloquea igual y antes que los productos (orden
        // fijo: cotización, variantes, productos simples): si dos ventas
        // llegan con el mismo quote_id, la segunda espera a la primera y
        // encuentra la cotización ya en "Convertida".
        $result = DB::transaction(function () use ($data, $lines, $request, $folioGenerator, $boxesNeededByVariant, $quantityNeededBySimpleProduct) {
            $quote = null;

            if (! empty($data['quote_id'])) {
                $quote = Quote::lockForUpdate()->findOrFail($data['quote_id']);

                if ($message = $quote->conversionBlockedMessage()) {
                    return response()->json(['message' => $message], 422);
                }
            }

            ksort($boxesNeededByVariant);
            ksort($quantityNeededBySimpleProduct);

            $variants = [];
            $simpleProducts = [];

            foreach ($boxesNeededByVariant as $variantId => $boxesNeeded) {
                $variant = $variants[$variantId] = ProductVariant::lockForUpdate()->findOrFail($variantId);

                if (! $variant->hasSufficientStock($boxesNeeded)) {
                    return response()->json([
                        'message' => "Stock insuficiente para {$variant->code}. Disponible: {$variant->stock_boxes} cajas, se requieren {$boxesNeeded}.",
                    ], 422);
                }
            }

            foreach ($quantityNeededBySimpleProduct as $simpleProductId => $quantityNeeded) {
                $simpleProduct = $simpleProducts[$simpleProductId] = SimpleProduct::lockForUpdate()->findOrFail($simpleProductId);

                if (! $simpleProduct->hasSufficientStock($quantityNeeded)) {
                    return response()->json([
                        'message' => "Stock insuficiente para {$simpleProduct->name}. Disponible: {$simpleProduct->stock_quantity} unidades, se requieren {$quantityNeeded}.",
                    ], 422);
                }
            }

            $sale = Sale::create([
                'folio' => $folioGenerator->generate(),
                'quote_id' => $data['quote_id'] ?? null,
                'customer_id' => $data['customer_id'] ?? null,
                'subtotal' => 0,
                'total' => 0,
                'created_by' => $request->user()->id,
            ]);

            $subtotal = 0;

            foreach ($lines as $line) {
                $lineTotal = round($line['quantity'] * $line['unit_price'], 2);

                $sale->items()->create([
                    'product_variant_id' => $line['product_variant_id'] ?? null,
                    'simple_product_id' => $line['simple_product_id'] ?? null,
                    'boxes' => $line['boxes'] ?? null,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'price_type' => $line['price_type'] ?? 'retail',
                    'line_total' => $lineTotal,
                ]);

                $subtotal += $lineTotal;
            }

            // Los productos sin seguimiento de stock no se descuentan ni
            // dejan movimiento en el historial.
            foreach ($boxesNeededByVariant as $variantId => $boxesNeeded) {
                if (! $variants[$variantId]->tracksStock()) {
                    continue;
                }

                StockMovement::record($variants[$variantId], 'subtract', $boxesNeeded, 'sale', $sale);
            }

            foreach ($quantityNeededBySimpleProduct as $simpleProductId => $quantityNeeded) {
                if (! $simpleProducts[$simpleProductId]->tracksStock()) {
                    continue;
                }

                StockMovement::record($simpleProducts[$simpleProductId], 'subtract', $quantityNeeded, 'sale', $sale);
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

        // Cotización no convertible o stock insuficiente: la transacción
        // terminó sin escribir nada.
        if (! $result instanceof Sale) {
            return $result;
        }

        $sale = $result;

        $sale->load(['items.productVariant', 'items.simpleProduct', 'customer', 'quote']);

        return new SaleResource($sale);
    }
}
