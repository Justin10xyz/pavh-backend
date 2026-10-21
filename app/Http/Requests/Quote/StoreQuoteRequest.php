<?php

namespace App\Http\Requests\Quote;

use App\Models\Quote;
use App\Models\SimpleProduct;
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
     * `price_type` (retail|wholesale, default retail) solo aplica a productos
     * simples: elige su precio de menudeo o de mayoreo. En variantes se
     * rechaza (`prohibits`), igual que `boxes` en líneas simples.
     *
     * discount/down_payment/shipping_cost son opcionales (null = no aplica).
     * `payment_terms` (cash|credit) no admite null: si no llega, la columna
     * queda en su default `cash`. Con `credit` se exige `credit_due_date`.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->withoutTrashed()],
            'notes' => ['nullable', 'string'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'down_payment' => ['nullable', 'numeric', 'min:0'],
            'shipping_cost' => ['nullable', 'numeric', 'min:0'],
            'payment_terms' => ['sometimes', 'string', Rule::in(Quote::PAYMENT_TERMS)],
            'credit_due_date' => ['nullable', 'required_if:payment_terms,credit', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['nullable', 'required_without:items.*.simple_product_id', 'prohibits:items.*.simple_product_id,items.*.price_type', 'integer', Rule::exists('product_variants', 'id')->withoutTrashed()],
            'items.*.simple_product_id' => ['nullable', 'required_without:items.*.product_variant_id', 'prohibits:items.*.boxes', 'integer', Rule::exists('simple_products', 'id')->withoutTrashed()],
            'items.*.boxes' => ['nullable', 'required_with:items.*.product_variant_id', 'prohibits:items.*.quantity', 'integer', 'min:1'],
            'items.*.quantity' => ['nullable', 'required_with:items.*.simple_product_id', 'numeric', 'min:0.01'],
            'items.*.price_type' => ['nullable', Rule::in(SimpleProduct::PRICE_TYPES)],
        ];
    }
}
