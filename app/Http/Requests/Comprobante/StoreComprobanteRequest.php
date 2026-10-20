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
     * Que la cotización esté pagada y no tenga ya comprobante lo valida el
     * controlador con Quote::comprobanteConversionBlockedMessage() (mensaje en
     * español, dentro de la transacción); el índice UNIQUE de
     * comprobantes.quote_id lo respalda. Las líneas y montos no se reciben: se
     * copian de la cotización. Sin `date` se usa la fecha de hoy.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'quote_id' => ['required', 'integer', Rule::exists('quotes', 'id')->withoutTrashed()],
            'date' => ['nullable', 'date'],
        ];
    }
}
