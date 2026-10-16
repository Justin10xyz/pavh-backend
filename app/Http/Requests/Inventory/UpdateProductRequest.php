<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'supplier_id' => ['sometimes', 'integer', Rule::exists('suppliers', 'id')->withoutTrashed()],
            'category_id' => ['sometimes', 'integer', Rule::exists('categories', 'id')->withoutTrashed()],
            'unit_type_id' => ['sometimes', 'integer', 'exists:unit_types,id'],
            'purchase_unit' => ['sometimes', 'string'],
        ];
    }
}
