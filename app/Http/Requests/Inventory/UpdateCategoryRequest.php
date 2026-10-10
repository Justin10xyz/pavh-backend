<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $category = $this->route('category');

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('categories', 'name')->ignore($category->id)],
            'code_prefix' => ['required', 'string', 'max:255', Rule::unique('categories', 'code_prefix')->ignore($category->id)],
            'product_form_type' => ['sometimes', 'string', Rule::in(['variant', 'simple'])],
        ];
    }
}
