<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

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
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
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
            'stock_boxes' => ['sometimes', 'nullable', 'integer'],
            'minimum_stock' => ['sometimes', 'nullable', 'integer'],
            'commission_category_id' => ['sometimes', 'nullable', 'exists:commission_categories,id'],
            'pei' => ['sometimes', 'nullable', 'string', 'max:10'],
            'ett' => ['sometimes', 'nullable', 'integer', 'between:1,4'],
        ];
    }
}
