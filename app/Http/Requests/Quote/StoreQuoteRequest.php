<?php

namespace App\Http\Requests\Quote;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreQuoteRequest extends FormRequest
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
     * folio y unit_price se resuelven server-side (folio en QuoteFolioGenerator,
     * unit_price desde el precio actual de la variante) — deliberadamente no
     * tienen regla aquí para que cualquier valor enviado por el cliente se
     * descarte al llamar a validated().
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['nullable', 'required_without:items.*.simple_product_id', 'prohibits:items.*.simple_product_id', 'integer', 'exists:product_variants,id'],
            'items.*.simple_product_id' => ['nullable', 'required_without:items.*.product_variant_id', 'integer', 'exists:simple_products,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
        ];
    }
}
