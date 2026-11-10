<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Contracts\Validation\ValidationRule;
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

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $category = $this->route('category');

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('categories', 'name')->ignore($category->id)->withoutTrashed()],
            // El prefijo solo se usa para autogenerar códigos de variantes (VariantCodeGenerator),
            // así que es obligatorio en Pisos (variant) y opcional en General (simple).
            'code_prefix' => ['nullable', Rule::requiredIf(fn () => $this->input('product_form_type', $category->product_form_type) === 'variant'), 'string', 'max:10', 'regex:/^[A-Z]+(-[A-Z]+)*$/', Rule::unique('categories', 'code_prefix')->ignore($category->id)->withoutTrashed()],
            'product_form_type' => ['sometimes', 'string', Rule::in(['variant', 'simple'])],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => __('validation.custom.categories.name.unique'),
            'code_prefix.required' => __('validation.custom.categories.code_prefix.required'),
            'code_prefix.string' => __('validation.custom.categories.code_prefix.format'),
            'code_prefix.max' => __('validation.custom.categories.code_prefix.format'),
            'code_prefix.regex' => __('validation.custom.categories.code_prefix.format'),
            'code_prefix.unique' => __('validation.custom.categories.code_prefix.unique'),
        ];
    }
}
