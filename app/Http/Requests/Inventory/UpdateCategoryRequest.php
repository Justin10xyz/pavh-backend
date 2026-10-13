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

    // El prefijo forma parte del código de cada variante (PIS-CREATO-TAU-60X120),
    // así que se guarda siempre en mayúsculas. Se normaliza antes de validar para
    // que `unique` compare contra el valor que de verdad se va a guardar.
    protected function prepareForValidation(): void
    {
        if (is_string($this->code_prefix)) {
            $this->merge(['code_prefix' => mb_strtoupper($this->code_prefix)]);
        }
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
