<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSimpleProductRequest extends FormRequest
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
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->withoutTrashed()],
            // unique incluye los dados de baja: el índice de la base también los cuenta.
            'code' => ['nullable', 'string', 'max:50', 'regex:/^\S+$/', Rule::unique('simple_products', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            // Opcional: null significa que el producto no se vende a mayoreo.
            'price_wholesale' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
            'stock_quantity' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
