<?php

namespace App\Http\Requests\Quote;

use App\Models\SimpleProduct;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateQuoteRequest extends FormRequest
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
     * Un PUT reemplaza todas las líneas de la cotización, así que items sigue
     * siendo obligatorio (no "sometimes") aunque customer_id/notes sí lo sean.
     *
     * customer_id acepta clientes borrados a propósito: re-enviar el cliente
     * actual (ya borrado) no debe bloquear la edición. El rechazo de un cliente
     * borrado *nuevo* depende del modelo y vive en QuoteController@update.
     *
     * Cantidad por tipo de línea: mismo contrato que StoreQuoteRequest
     * (`boxes` en variantes, `quantity` en productos simples).
     *
     * `price_type` (retail|wholesale, default retail) solo aplica a productos
     * simples: elige su precio de menudeo o de mayoreo. En variantes se
     * rechaza (`prohibits`), igual que `boxes` en líneas simples.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['sometimes', 'nullable', 'integer', Rule::exists('customers', 'id')],
            'notes' => ['sometimes', 'nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['nullable', 'required_without:items.*.simple_product_id', 'prohibits:items.*.simple_product_id,items.*.price_type', 'integer', Rule::exists('product_variants', 'id')->withoutTrashed()],
            'items.*.simple_product_id' => ['nullable', 'required_without:items.*.product_variant_id', 'prohibits:items.*.boxes', 'integer', Rule::exists('simple_products', 'id')->withoutTrashed()],
            'items.*.boxes' => ['nullable', 'required_with:items.*.product_variant_id', 'prohibits:items.*.quantity', 'integer', 'min:1'],
            'items.*.quantity' => ['nullable', 'required_with:items.*.simple_product_id', 'numeric', 'min:0.01'],
            'items.*.price_type' => ['nullable', Rule::in(SimpleProduct::PRICE_TYPES)],
        ];
    }
}
