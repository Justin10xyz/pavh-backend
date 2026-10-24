<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFloorStyleRequest extends FormRequest
{
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
        $floorStyle = $this->route('floorStyle');

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('floor_styles', 'name')->ignore($floorStyle->id)->withoutTrashed()],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => __('validation.custom.floor_styles.name.unique'),
        ];
    }
}
