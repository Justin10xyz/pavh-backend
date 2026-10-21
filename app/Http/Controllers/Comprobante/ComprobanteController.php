<?php

namespace App\Http\Controllers\Comprobante;

use App\Http\Controllers\Controller;
use App\Http\Requests\Comprobante\StoreComprobanteRequest;
use App\Http\Requests\Comprobante\UpdateComprobanteDateRequest;
use App\Http\Resources\Comprobante\ComprobanteResource;
use App\Models\BusinessSetting;
use App\Models\Comprobante;
use App\Models\Quote;
use App\Services\AmountInWords;
use App\Services\ComprobanteFolioGenerator;
use App\Services\ComprobantePdfGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ComprobanteController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $allowedRelations = [
            'customer' => 'customer',
        ];

        $with = collect(explode(',', $request->query('with', '')))
            ->map(fn ($relation) => trim($relation))
            ->filter(fn ($relation) => array_key_exists($relation, $allowedRelations))
            ->flatMap(fn ($relation) => (array) $allowedRelations[$relation])
            ->all();

        $comprobantes = Comprobante::query()
            ->when($with !== [], fn ($query) => $query->with($with))
            ->latest()
            ->get();

        return ComprobanteResource::collection($comprobantes);
    }

    public function show(Comprobante $comprobante): ComprobanteResource
    {
        // withTrashed: un comprobante emitido se debe poder consultar aunque
        // la variante, el producto o la cotización de origen se den de baja
        // después (el cliente ya lo trae la relación del modelo).
        $comprobante->load([
            'customer',
            'quote' => fn ($query) => $query->withTrashed(),
            'items.productVariant' => fn ($query) => $query->withTrashed(),
            'items.productVariant.product' => fn ($query) => $query->withTrashed(),
            'items.productVariant.product.unitType',
            'items.simpleProduct' => fn ($query) => $query->withTrashed(),
        ]);

        return new ComprobanteResource($comprobante);
    }

    /**
     * Convierte una cotización pagada en comprobante. No recibe líneas ni
     * montos: copia quote_items y subtotal/total de la cotización tal cual (ya
     * congelados), sin recalcular contra el catálogo actual.
     *
     * El guard se vuelve a validar aquí, con la cotización bloqueada: no se
     * confía en lo que mostró GET /quotes/{id}/to-comprobante, y dos
     * conversiones concurrentes se serializan (la segunda ya la encuentra con
     * comprobante).
     */
    public function store(
        StoreComprobanteRequest $request,
        ComprobanteFolioGenerator $folioGenerator,
        AmountInWords $amountInWords,
    ): ComprobanteResource|JsonResponse {
        $data = $request->validated();

        $result = DB::transaction(function () use ($data, $request, $folioGenerator, $amountInWords) {
            $quote = Quote::lockForUpdate()->findOrFail($request->integer('quote_id'));

            if ($message = $quote->comprobanteConversionBlockedMessage()) {
                return response()->json(['message' => $message], 422);
            }

            $comprobante = Comprobante::create([
                'folio' => $folioGenerator->generate(),
                'quote_id' => $quote->id,
                'customer_id' => $quote->customer_id,
                'date' => $data['date'] ?? now()->toDateString(),
                'subtotal' => $quote->subtotal,
                'total' => $quote->total,
                'total_in_words' => $amountInWords->pesos($quote->total),
                'created_by' => $request->user()->id,
            ]);

            foreach ($quote->items as $item) {
                $comprobante->items()->create($item->only([
                    'product_variant_id',
                    'simple_product_id',
                    'boxes',
                    'quantity',
                    'unit_price',
                    'price_type',
                    'line_total',
                ]));
            }

            return $comprobante;
        });

        // Cotización sin pagar o con comprobante: la transacción terminó sin
        // escribir nada.
        if (! $result instanceof Comprobante) {
            return $result;
        }

        $result->load(['items.productVariant', 'items.simpleProduct', 'customer', 'quote']);

        return new ComprobanteResource($result);
    }

    /**
     * Endpoint dedicado a la fecha, lo único editable de un comprobante. Sin
     * guard de estado: la fecha queda editable sin límite.
     */
    public function updateDate(UpdateComprobanteDateRequest $request, Comprobante $comprobante): ComprobanteResource
    {
        $comprobante->update($request->validated());

        $comprobante->load(['items.productVariant', 'items.simpleProduct', 'customer', 'quote']);

        return new ComprobanteResource($comprobante);
    }

    /**
     * Nunca se bloquea por datos faltantes (Configuración vacía, comprobante
     * sin cliente): el comprobante ya existe y su PDF se genera con esos
     * espacios en blanco.
     */
    public function downloadPdf(Comprobante $comprobante, ComprobantePdfGenerator $pdfGenerator): StreamedResponse
    {
        // withTrashed: un comprobante viejo se debe poder reimprimir aunque la
        // variante o el producto se hayan dado de baja después.
        $comprobante->load([
            'customer',
            'items.productVariant' => fn ($query) => $query->withTrashed(),
            'items.productVariant.product' => fn ($query) => $query->withTrashed(),
            'items.productVariant.product.unitType',
            'items.simpleProduct' => fn ($query) => $query->withTrashed(),
        ]);

        $business = BusinessSetting::current();

        $pdf = $pdfGenerator->generate([
            'folio' => $comprobante->folio,
            'date' => $comprobante->date->format('d/m/Y'),
            'business' => $business->only([
                'place_of_issue',
                'business_regime',
                'tax_id',
                'tax_id_number',
                'address',
                'neighborhood',
                'city',
                'postal_code',
                'phone_primary',
                'phone_secondary',
                'email',
            ]),
            'customer' => $comprobante->customer?->only(['name', 'address', 'neighborhood', 'city', 'postal_code', 'phone']),
            'items' => $comprobante->items->map(fn ($item) => [
                // Producto simple: solo su nombre (no tiene línea/color/medida).
                'description' => $item->simpleProduct
                    ? $item->simpleProduct->name
                    : trim(implode(' ', [
                        $item->productVariant->product->name,
                        $item->productVariant->color,
                        $item->productVariant->size,
                    ])),
                'unit' => $item->simple_product_id || $item->productVariant->isSoldByPiece() ? 'uds' : 'm2',
                'boxes' => $item->boxes,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'line_total' => (float) $item->line_total,
            ])->all(),
            'total' => (float) $comprobante->total,
            'total_in_words' => $comprobante->total_in_words,
        ]);

        return response()->streamDownload(
            fn () => print ($pdf),
            "Comprobante-{$comprobante->folio}.pdf",
            ['Content-Type' => 'application/pdf'],
        );
    }
}
