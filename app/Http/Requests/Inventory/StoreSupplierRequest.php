<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('suppliers', 'name')->withoutTrashed()],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => __('validation.custom.suppliers.name.unique'),
        ];
    }
}
