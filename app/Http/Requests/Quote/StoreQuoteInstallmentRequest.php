<?php

namespace App\Http\Requests\Quote;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreQuoteInstallmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * El tope de `amount` es el de la columna decimal(10,2); el límite real
     * (no mayor que el saldo pendiente) depende de la cotización y lo revisa
     * QuoteInstallmentController dentro de la transacción. La fecha no puede
     * ser futura: un abono registra dinero ya recibido.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
            'payment_date' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
