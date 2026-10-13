<?php

namespace App\Http\Requests\Quote;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
     * Cantidad según el tipo de línea (mismo contrato que StoreSaleRequest):
     * - Variante: `boxes` (cajas completas, entero); sus m² (`quantity`) los
     *   deriva el backend como boxes × m2_per_box y no se aceptan del cliente.
     * - Producto simple: `quantity` en unidades; `boxes` no aplica.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->withoutTrashed()],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['nullable', 'required_without:items.*.simple_product_id', 'prohibits:items.*.simple_product_id', 'integer', Rule::exists('product_variants', 'id')->withoutTrashed()],
            'items.*.simple_product_id' => ['nullable', 'required_without:items.*.product_variant_id', 'prohibits:items.*.boxes', 'integer', Rule::exists('simple_products', 'id')->withoutTrashed()],
            'items.*.boxes' => ['nullable', 'required_with:items.*.product_variant_id', 'prohibits:items.*.quantity', 'integer', 'min:1'],
            'items.*.quantity' => ['nullable', 'required_with:items.*.simple_product_id', 'numeric', 'min:0.01'],
        ];
    }
}
