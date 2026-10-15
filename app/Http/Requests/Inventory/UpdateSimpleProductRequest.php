<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSimpleProductRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    // Mismo criterio que code_prefix de categoría: se guarda en mayúsculas y se
    // normaliza antes de validar para que `unique` compare contra lo que se guarda.
    protected function prepareForValidation(): void
    {
        if (is_string($this->code)) {
            $this->merge(['code' => mb_strtoupper(trim($this->code))]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'category_id' => ['sometimes', 'integer', Rule::exists('categories', 'id')->withoutTrashed()],
            // unique incluye los dados de baja: el índice de la base también los cuenta.
            'code' => ['sometimes', 'nullable', 'string', 'max:50', 'regex:/^\S+$/', Rule::unique('simple_products', 'code')->ignore($this->route('simple_product'))],
            'name' => ['sometimes', 'string', 'max:255'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'description' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
