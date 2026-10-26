<?php

namespace App\Http\Requests\Sale;

use App\Models\SimpleProduct;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSaleRequest extends FormRequest
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
     * A diferencia de quotes, aquí unit_price SÍ viene del cliente (el precio
     * de la venta lo decide el vendedor) — folio sigue sin regla,
     * se resuelve server-side vía SaleFolioGenerator.
     *
     * Cantidad según el tipo de línea:
     * - Variante: `boxes` (cajas completas, entero). Sus m² (`quantity`) los
     *   deriva el backend como boxes × m2_per_box, así que no se aceptan del
     *   cliente (`prohibits`), igual que el folio.
     * - Producto simple: `quantity` en unidades; `boxes` no aplica.
     *
     * `price_type` (retail|wholesale, default retail) solo aplica a productos
     * simples y se guarda tal cual llega, sin compararlo contra unit_price
     * (el precio de la venta lo decide el vendedor). En variantes se rechaza
     * (`prohibits`), igual que `boxes` en líneas simples.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->withoutTrashed()],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['nullable', 'required_without:items.*.simple_product_id', 'prohibits:items.*.simple_product_id,items.*.price_type', 'integer', Rule::exists('product_variants', 'id')->withoutTrashed()],
            'items.*.simple_product_id' => ['nullable', 'required_without:items.*.product_variant_id', 'prohibits:items.*.boxes', 'integer', Rule::exists('simple_products', 'id')->withoutTrashed()],
            'items.*.boxes' => ['nullable', 'required_with:items.*.product_variant_id', 'prohibits:items.*.quantity', 'integer', 'min:1'],
            'items.*.quantity' => ['nullable', 'required_with:items.*.simple_product_id', 'numeric', 'min:0.01'],
            'items.*.price_type' => ['nullable', Rule::in(SimpleProduct::PRICE_TYPES)],
            'items.*.unit_price' => ['required', 'numeric', 'min:0.01'],
        ];
    }
}
