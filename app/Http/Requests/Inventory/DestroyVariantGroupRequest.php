<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DestroyVariantGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $product = $this->route('product');

        // exists acotado al producto de la ruta y sin las ya dadas de baja: el
        // error queda en variant_ids.N y el mensaje nombra el id rechazado.
        return [
            'variant_ids' => ['required', 'array', 'min:1'],
            'variant_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('product_variants', 'id')->where('product_id', $product->id)->withoutTrashed(),
            ],
        ];
    }
}
