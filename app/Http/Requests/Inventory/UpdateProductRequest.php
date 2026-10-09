<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProductRequest extends FormRequest
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
            'name' => ['sometimes', 'string'],
            'supplier_id' => ['sometimes', 'integer', 'exists:suppliers,id'],
            'category_id' => ['sometimes', 'integer', 'exists:categories,id'],
            'unit_type_id' => ['sometimes', 'integer', 'exists:unit_types,id'],
            'purchase_unit' => ['sometimes', 'string'],
        ];
    }
}
