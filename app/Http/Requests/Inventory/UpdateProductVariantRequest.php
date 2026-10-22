<?php

namespace App\Http\Requests\Inventory;

use App\Models\ProductVariant;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductVariantRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * La medida se guarda sin espacios y con "X" mayúscula (ej. "60 x 60" →
     * "60X60"): groupVariants.js agrupa por medida exacta y cualquier otra
     * escritura separaría el grupo.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->size)) {
            $this->merge(['size' => mb_strtoupper(preg_replace('/\s+/u', '', $this->size))]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // stock_boxes no se acepta aquí (validated() lo descarta si llega): el
        // stock solo cambia por PATCH .../stock, que deja historial.
        return [
            'product_id' => ['sometimes', 'integer', 'exists:products,id'],
            'color' => ['sometimes', 'string'],
            'size' => ['sometimes', 'string'],
            'price_per_m2' => ['sometimes', 'numeric'],
            'price_per_box' => ['sometimes', 'numeric'],
            'pieces_per_box' => ['sometimes', 'nullable', 'integer'],
            'm2_per_box' => ['sometimes', 'nullable', 'numeric'],
            'kilos_per_box' => ['sometimes', 'nullable', 'numeric'],
            'boxes_per_pallet' => ['sometimes', 'nullable', 'integer'],
            'minimum_stock' => ['sometimes', 'nullable', 'integer'],
            'commission_category_id' => ['sometimes', 'nullable', 'exists:commission_categories,id'],
            'pei' => ['sometimes', 'nullable', Rule::in(ProductVariant::PEI_VALUES)],
            'ett' => ['sometimes', 'nullable', 'integer', 'between:1,4'],
            'face_count' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }
}
