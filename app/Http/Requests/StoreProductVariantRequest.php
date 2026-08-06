<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductVariantRequest extends FormRequest
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
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'color' => ['required', 'string'],
            'size' => ['required', 'string'],
            'price_per_m2' => ['required', 'numeric'],
            'price_per_box' => ['required', 'numeric'],
            'pieces_per_box' => ['nullable', 'integer'],
            'm2_per_box' => ['nullable', 'numeric'],
            'kilos_per_box' => ['nullable', 'numeric'],
            'boxes_per_pallet' => ['nullable', 'integer'],
            'minimum_stock' => ['nullable', 'integer'],
        ];
    }
}
