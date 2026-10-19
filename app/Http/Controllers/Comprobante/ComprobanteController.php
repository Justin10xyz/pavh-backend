<?php

namespace App\Http\Controllers\Comprobante;

use App\Http\Controllers\Controller;
use App\Http\Requests\Comprobante\StoreComprobanteRequest;
use App\Http\Requests\Comprobante\UpdateComprobanteDateRequest;
use App\Http\Resources\Comprobante\ComprobanteResource;
use App\Models\Comprobante;
use App\Models\Quote;
use App\Services\AmountInWords;
use App\Services\ComprobanteFolioGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ComprobanteController extends Controller
{
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
}
