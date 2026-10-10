<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:categories,name'],
            'code_prefix' => ['required', 'string', 'max:255', 'unique:categories,code_prefix'],
            'product_form_type' => ['sometimes', 'string', Rule::in(['variant', 'simple'])],
        ];
    }
}


