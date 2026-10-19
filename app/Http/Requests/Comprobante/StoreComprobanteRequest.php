<?php

namespace App\Http\Requests\Comprobante;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreComprobanteRequest extends FormRequest
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
     * folio (ComprobanteFolioGenerator) y total_in_words se resuelven
     * server-side — deliberadamente no tienen regla aquí para que cualquier
     * valor enviado por el cliente se descarte al llamar a validated().
     *
     * `quote_id` es único: una cotización genera a lo más un comprobante. El
     * `unique` incluye los dados de baja porque el índice de la base también
     * los cuenta.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'quote_id' => ['required', 'integer', Rule::exists('quotes', 'id')->withoutTrashed(), Rule::unique('comprobantes', 'quote_id')],
            'date' => ['required', 'date'],
        ];
    }
}
