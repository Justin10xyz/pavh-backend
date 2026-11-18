<?php

namespace App\Http\Controllers\Quote;

use App\Http\Controllers\Controller;
use App\Http\Requests\Quote\StoreQuoteInstallmentRequest;
use App\Http\Resources\Quote\QuoteInstallmentResource;
use App\Models\Quote;
use App\Models\QuoteInstallment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Abonos de una cotización: pagos parciales que se restan de su saldo. Solo
 * en Borrador (Quote::installmentBlockedMessage()). Mismo patrón que
 * QuoteController::markPaid(): la cotización se bloquea (lockForUpdate)
 * dentro de la transacción y el guard se revalida ya bloqueada, así que dos
 * abonos simultáneos se serializan y el segundo ve el saldo que dejó el primero.
 */
class QuoteInstallmentController extends Controller
{
    public function store(StoreQuoteInstallmentRequest $request, Quote $quote): JsonResponse
    {
        $data = $request->validated();

        $result = DB::transaction(function () use ($request, $quote, $data) {
            $quote = Quote::lockForUpdate()->findOrFail($quote->id);

            if ($message = $quote->installmentBlockedMessage()) {
                return response()->json(['message' => $message], 422);
            }

            if ($message = $this->amountRejectionMessage($quote->balance(), (float) $data['amount'])) {
                return response()->json(['message' => $message], 422);
            }

            $installment = $quote->installments()->create([
                ...$data,
                'created_by' => $request->user()->id,
            ]);

            return [$quote, $installment];
        });

        // Cotización no abonable o monto mayor que el saldo: la transacción
        // terminó sin escribir nada.
        if ($result instanceof JsonResponse) {
            return $result;
        }

        [$quote, $installment] = $result;

        return (new QuoteInstallmentResource($installment))
            ->additional(['quote' => $this->quoteTotals($quote)])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Anular = soft delete: el abono deja de contar en el saldo pero se
     * conserva. El {installment} ya llega acotado a la cotización
     * (scopeBindings): uno de otra cotización o ya anulado da 404.
     */
    public function destroy(Quote $quote, QuoteInstallment $installment): JsonResponse
    {
        return DB::transaction(function () use ($quote, $installment) {
            $quote = Quote::lockForUpdate()->findOrFail($quote->id);

            if ($message = $quote->installmentBlockedMessage(voiding: true)) {
                return response()->json(['message' => $message], 422);
            }

            $installment->delete();

            return response()->json(['quote' => $this->quoteTotals($quote)]);
        });
    }

    /**
     * Va en el controlador y no en el Form Request: depende del saldo de la
     * cotización ya bloqueada, no del payload.
     */
    private function amountRejectionMessage(float $balance, float $amount): ?string
    {
        if ($balance <= 0) {
            return 'Esta cotización no tiene saldo pendiente; no se pueden registrar abonos.';
        }

        if (round($amount, 2) <= $balance) {
            return null;
        }

        $formattedAmount = number_format($amount, 2);
        $formattedBalance = number_format($balance, 2);

        return "El abono (\${$formattedAmount}) no puede ser mayor que el saldo pendiente (\${$formattedBalance}).";
    }

    /**
     * Saldo y total de abonos ya recalculados, con el mismo formato que
     * QuoteResource, para que el frontend actualice el detalle sin refetch.
     *
     * @return array{id: int, installments_total: string, balance: string}
     */
    private function quoteTotals(Quote $quote): array
    {
        $quote->unsetRelation('installments');

        return [
            'id' => $quote->id,
            'installments_total' => number_format($quote->installmentsTotal(), 2, '.', ''),
            'balance' => number_format($quote->balance(), 2, '.', ''),
        ];
    }
}
