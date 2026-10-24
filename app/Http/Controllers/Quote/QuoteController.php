<?php

namespace App\Http\Controllers\Quote;

use App\Http\Controllers\Controller;
use App\Http\Requests\Quote\StoreQuoteRequest;
use App\Http\Requests\Quote\UpdateQuoteRequest;
use App\Http\Resources\Quote\QuoteItemResource;
use App\Http\Resources\Quote\QuoteResource;
use App\Models\BusinessSetting;
use App\Models\Customer;
use App\Models\ProductVariant;
use App\Models\Quote;
use App\Models\QuoteStatus;
use App\Models\SimpleProduct;
use App\Models\StockMovement;
use App\Services\AmountInWords;
use App\Services\QuoteFolioGenerator;
use App\Services\QuotePdfGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class QuoteController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
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

    public function show(Quote $quote): QuoteResource
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
            // Mismo criterio: uno dado de baja sigue ocupando el quote_id
            // (UNIQUE), así que el detalle debe seguir mostrándolo.
            'comprobante' => fn ($query) => $query->withTrashed(),
        ]);

        return new QuoteResource($quote);
    }

    public function store(StoreQuoteRequest $request, QuoteFolioGenerator $folioGenerator): QuoteResource|JsonResponse
    {
        $data = $request->validated();

        if ($message = $this->lineRejectionMessage($data['items'])) {
            return response()->json(['message' => $message], 422);
        }

        $lines = $this->resolveLines($data['items']);
        $subtotal = $this->linesSubtotal($lines);
        $discount = $data['discount'] ?? null;
        $shippingCost = $data['shipping_cost'] ?? null;

        if ($message = $this->discountRejectionMessage($subtotal, $discount)) {
            return response()->json(['message' => $message], 422);
        }

        // De contado (también por default si no llega) no hay vencimiento: se
        // limpia aunque llegue en el payload, igual que en update.
        if (($data['payment_terms'] ?? 'cash') === 'cash') {
            $data['credit_due_date'] = null;
        }

        $quote = DB::transaction(function () use ($data, $lines, $subtotal, $discount, $shippingCost, $request, $folioGenerator) {
            $draftStatus = QuoteStatus::where('name', 'Borrador')->firstOrFail();

            $data['folio'] = $folioGenerator->generate();
            $data['quote_status_id'] = $draftStatus->id;
            $data['subtotal'] = $subtotal;
            $data['total'] = $this->totalFor($subtotal, $discount, $shippingCost);
            $data['created_by'] = $request->user()->id;

            $quote = Quote::create($data);

            $this->createItems($quote, $lines);

            return $quote;
        });

        // payment_terms tiene default en la BD y create() no lo refresca.
        $quote->refresh();

        $quote->load(['items.productVariant', 'items.simpleProduct', 'customer', 'quoteStatus']);

        return new QuoteResource($quote);
    }

    public function update(UpdateQuoteRequest $request, Quote $quote): QuoteResource|JsonResponse
    {
        // Pagada: su stock ya se descontó según las líneas actuales. Va antes
        // del guard de estado para dar el mensaje específico.
        if ($quote->paid_at !== null) {
            return response()->json([
                'message' => 'Esta cotización ya fue pagada y no se puede editar.',
            ], 422);
        }

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

        $lines = $this->resolveLines($data['items']);
        $subtotal = $this->linesSubtotal($lines);
        // discount es `sometimes`: si no llega se conserva el guardado.
        $discount = array_key_exists('discount', $data) ? $data['discount'] : $quote->discount;
        $shippingCost = array_key_exists('shipping_cost', $data) ? $data['shipping_cost'] : $quote->shipping_cost;

        if ($message = $this->discountRejectionMessage($subtotal, $discount)) {
            return response()->json(['message' => $message], 422);
        }

        // De contado no hay vencimiento: se limpia aunque llegue en el payload.
        if (($data['payment_terms'] ?? $quote->payment_terms) === 'cash') {
            $data['credit_due_date'] = null;
        }

        DB::transaction(function () use ($quote, $data, $lines, $subtotal, $discount, $shippingCost) {
            $quote->update([
                ...$data,
                'subtotal' => $subtotal,
                'total' => $this->totalFor($subtotal, $discount, $shippingCost),
            ]);

            $quote->items()->delete();

            $this->createItems($quote, $lines);
        });

        $quote->load(['items.productVariant', 'items.simpleProduct', 'customer', 'quoteStatus']);

        return new QuoteResource($quote);
    }

    public function destroy(Quote $quote): Response|JsonResponse
    {
        if ($message = $quote->deletionBlockedMessage()) {
            return response()->json(['message' => $message], 422);
        }

        // Soft delete: las líneas se conservan para que restore() regrese la
        // cotización completa.
        $quote->delete();

        return response()->noContent();
    }

    /**
     * Solo cambia el estado; no toca stock ni ventas.
     */
    public function cancel(Quote $quote): QuoteResource|JsonResponse
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
     * Marca la cotización como pagada y descuenta el stock de sus líneas, como
     * una venta: valida stock suficiente de todo (agregado por producto) antes
     * de mutar nada, y cada descuento pasa por StockMovement::record() con
     * origen 'quote_payment'. Los productos sin seguimiento no bloquean ni se
     * descuentan.
     *
     * Mismo orden de bloqueo que SaleController@store (cotización, variantes,
     * productos simples, por id): dos pagos concurrentes de la misma cotización
     * se serializan y el segundo la encuentra ya pagada.
     */
    public function markPaid(Request $request, Quote $quote): QuoteResource|JsonResponse
    {
        $result = DB::transaction(function () use ($request, $quote) {
            $quote = Quote::lockForUpdate()->findOrFail($quote->id);

            if ($message = $quote->markPaidBlockedMessage()) {
                return response()->json(['message' => $message], 422);
            }

            $boxesNeededByVariant = [];
            $quantityNeededBySimpleProduct = [];

            foreach ($quote->items as $item) {
                if ($item->simple_product_id !== null) {
                    $quantityNeededBySimpleProduct[$item->simple_product_id] = ($quantityNeededBySimpleProduct[$item->simple_product_id] ?? 0) + (int) $item->quantity;

                    continue;
                }

                // Línea previa a la venta por caja completa sin m2_per_box: no
                // hay forma de saber cuántas cajas descontar.
                if ($item->boxes === null) {
                    return response()->json([
                        'message' => 'Una línea de la cotización no tiene cajas capturadas; edítala antes de marcarla como pagada.',
                    ], 422);
                }

                $boxesNeededByVariant[$item->product_variant_id] = ($boxesNeededByVariant[$item->product_variant_id] ?? 0) + $item->boxes;
            }

            ksort($boxesNeededByVariant);
            ksort($quantityNeededBySimpleProduct);

            $variants = [];
            $simpleProducts = [];

            // withTrashed() solo para nombrar en el mensaje un producto dado
            // de baja; igual que en convert, no se deja pasar.
            foreach ($boxesNeededByVariant as $variantId => $boxesNeeded) {
                $variant = $variants[$variantId] = ProductVariant::withTrashed()->lockForUpdate()->findOrFail($variantId);

                if ($variant->trashed()) {
                    return response()->json([
                        'message' => "No se puede marcar como pagada: la variante {$variant->code} ya no está disponible.",
                    ], 422);
                }

                if (! $variant->hasSufficientStock($boxesNeeded)) {
                    return response()->json([
                        'message' => "Stock insuficiente para {$variant->code}. Disponible: {$variant->stock_boxes} cajas, se requieren {$boxesNeeded}.",
                    ], 422);
                }
            }

            foreach ($quantityNeededBySimpleProduct as $simpleProductId => $quantityNeeded) {
                $simpleProduct = $simpleProducts[$simpleProductId] = SimpleProduct::withTrashed()->lockForUpdate()->findOrFail($simpleProductId);

                if ($simpleProduct->trashed()) {
                    return response()->json([
                        'message' => "No se puede marcar como pagada: el producto {$simpleProduct->name} ya no está disponible.",
                    ], 422);
                }

                if (! $simpleProduct->hasSufficientStock($quantityNeeded)) {
                    return response()->json([
                        'message' => "Stock insuficiente para {$simpleProduct->name}. Disponible: {$simpleProduct->stock_quantity} unidades, se requieren {$quantityNeeded}.",
                    ], 422);
                }
            }

            foreach ($boxesNeededByVariant as $variantId => $boxesNeeded) {
                if ($variants[$variantId]->tracksStock()) {
                    StockMovement::record($variants[$variantId], 'subtract', $boxesNeeded, 'quote_payment', quote: $quote);
                }
            }

            foreach ($quantityNeededBySimpleProduct as $simpleProductId => $quantityNeeded) {
                if ($simpleProducts[$simpleProductId]->tracksStock()) {
                    StockMovement::record($simpleProducts[$simpleProductId], 'subtract', $quantityNeeded, 'quote_payment', quote: $quote);
                }
            }

            $paidStatus = QuoteStatus::where('name', 'Pagada')->firstOrFail();

            $quote->update([
                'quote_status_id' => $paidStatus->id,
                'paid_at' => now(),
                'paid_by' => $request->user()->id,
            ]);

            return $quote;
        });

        // Cotización no pagable o stock insuficiente: la transacción terminó
        // sin escribir nada.
        if (! $result instanceof Quote) {
            return $result;
        }

        $result->load(['items.productVariant', 'items.simpleProduct', 'customer', 'quoteStatus']);

        return new QuoteResource($result);
    }

    /**
     * Payload de solo lectura para prellenar el form de nueva venta. No muta
     * nada — la conversión real ocurre al confirmar POST /api/sales con
     * quote_id, que marca la cotización como Convertida.
     */
    public function convert(Quote $quote): JsonResponse
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

                    return $variant->code ?? "ID {$item->product_variant_id}";
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

                    return $simpleProduct->name ?? "ID {$item->simple_product_id}";
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

    /**
     * Payload de solo lectura para mostrar el comprobante antes de confirmarlo.
     * No muta nada — el comprobante se crea al confirmar POST
     * /api/comprobantes con quote_id, que copia líneas y montos tal cual.
     */
    public function toComprobante(Quote $quote, AmountInWords $amountInWords): JsonResponse
    {
        if ($message = $quote->comprobanteConversionBlockedMessage()) {
            return response()->json(['message' => $message], 422);
        }

        // withTrashed: el comprobante copia la cotización tal cual, aunque un
        // producto se haya dado de baja después de pagarla.
        $quote->load([
            'customer',
            'items.productVariant' => fn ($query) => $query->withTrashed(),
            'items.productVariant.product' => fn ($query) => $query->withTrashed(),
            'items.productVariant.product.unitType',
            'items.simpleProduct' => fn ($query) => $query->withTrashed(),
        ]);

        return response()->json([
            'data' => [
                'quote_id' => $quote->id,
                'quote_folio' => $quote->folio,
                'customer_id' => $quote->customer_id,
                'customer' => $quote->customer?->only(['id', 'name', 'phone', 'email', 'address', 'neighborhood', 'city', 'postal_code']),
                'date' => now()->toDateString(),
                'items' => QuoteItemResource::collection($quote->items),
                'subtotal' => $quote->subtotal,
                'total' => $quote->total,
                'total_in_words' => $amountInWords->pesos($quote->total),
            ],
        ]);
    }

    /**
     * PDF del Presupuesto. Como el del comprobante, nunca se bloquea por datos
     * faltantes: Configuración vacía o cotización sin cliente dejan espacios
     * en blanco.
     */
    public function downloadPdf(Quote $quote, QuotePdfGenerator $pdfGenerator): StreamedResponse
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

        $business = BusinessSetting::current();

        $pdf = $pdfGenerator->generate([
            'folio' => $quote->folio,
            'date' => $quote->created_at->format('d/m/Y'),
            'business' => $business->only([
                'address',
                'neighborhood',
                'city',
                'postal_code',
                'phone_primary',
                'phone_secondary',
                'email',
            ]),
            'customer' => $quote->customer?->only(['name', 'address', 'neighborhood', 'city', 'postal_code', 'phone']),
            'items' => $quote->items->map(fn ($item) => [
                // Producto simple: solo su nombre (no tiene línea/color/medida).
                'description' => $item->simpleProduct
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
                'line_total' => (float) $item->line_total,
            ])->all(),
            'subtotal' => (float) $quote->subtotal,
            'discount' => (float) $quote->discount,
            'shipping_cost' => (float) $quote->shipping_cost,
            'total' => (float) $quote->total,
            'down_payment' => (float) $quote->down_payment,
            'balance' => $quote->balance(),
            'payment_terms' => $quote->payment_terms,
            'credit_due_date' => $quote->credit_due_date?->format('d/m/Y'),
        ]);

        return response()->streamDownload(
            fn () => print ($pdf),
            "Presupuesto-{$quote->folio}.pdf",
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * Va antes de la transacción (no dentro de resolveLines) para rechazar sin
     * haber creado la cotización ni borrado las líneas existentes en update.
     *
     * - Producto simple: cantidad fraccionaria, o línea de mayoreo sin
     *   price_wholesale configurado (nunca cae al precio de menudeo).
     * - Variante sin m2_per_box: no hay forma de derivar los m² que cubren las
     *   cajas, mismo rechazo que en SaleController@store (nunca 1:1). No
     *   aplica a variantes por pieza, que no derivan m².
     *
     * @param  array<int, array<string, mixed>>  $items  Líneas ya validadas del request.
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

    /**
     * Resuelve cada línea a lo que se va a guardar, sin escribir nada: así el
     * subtotal se conoce antes de la transacción y el guard de descuento puede
     * rechazar sin haber creado la cotización ni borrado sus líneas.
     *
     * @param  array<int, array<string, mixed>>  $items  Líneas ya validadas del request.
     * @return array<int, array<string, mixed>>
     */
    private function resolveLines(array $items): array
    {
        $lines = [];

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

            $lines[] = [
                'product_variant_id' => $item['product_variant_id'] ?? null,
                'simple_product_id' => $item['simple_product_id'] ?? null,
                'boxes' => $boxes,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'price_type' => $priceType,
                'line_total' => round($quantity * $unitPrice, 2),
            ];
        }

        return $lines;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines  Salida de resolveLines().
     */
    private function linesSubtotal(array $lines): float
    {
        return round(array_sum(array_column($lines, 'line_total')), 2);
    }

    /**
     * Total = subtotal - descuento + envío; descuento o envío null (no aplica)
     * cuentan como 0. Es el mismo Total que muestra el PDF y que copia el
     * comprobante.
     *
     * TODO: tax si aplica en el futuro
     */
    private function totalFor(float $subtotal, mixed $discount, mixed $shippingCost): float
    {
        return round($subtotal - (float) $discount + (float) $shippingCost, 2);
    }

    /**
     * Va en el controlador y no en el Form Request: depende del subtotal ya
     * calculado de las líneas, no del payload crudo.
     *
     * Se evalúa contra subtotal - descuento, no contra el total: el envío no
     * debe poder "tapar" un descuento mayor que el subtotal.
     */
    private function discountRejectionMessage(float $subtotal, mixed $discount): ?string
    {
        if (round($subtotal - (float) $discount, 2) >= 0) {
            return null;
        }

        $formattedDiscount = number_format((float) $discount, 2);
        $formattedSubtotal = number_format($subtotal, 2);

        return "El descuento (\${$formattedDiscount}) no puede ser mayor que el subtotal (\${$formattedSubtotal}).";
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines  Salida de resolveLines().
     */
    private function createItems(Quote $quote, array $lines): void
    {
        foreach ($lines as $line) {
            $quote->items()->create($line);
        }
    }
}
