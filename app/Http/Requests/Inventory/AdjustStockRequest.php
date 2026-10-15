<?php

namespace App\Http\Requests\Inventory;

use App\Models\StockMovement;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdjustStockRequest extends FormRequest
{
    /**
     * Acciones del endpoint: los tipos de movimiento más 'deactivate', que
     * no es un tipo en sí (se registra como 'subtract' con origen
     * 'deactivated') y no lleva cantidad: retira todo el stock actual.
     */
    public const ACTIONS = [...StockMovement::TYPES, 'deactivate'];

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
        // 0 solo es válido al activar el seguimiento (producto sin stock): el
        // controlador lo rechaza si el producto ya lleva seguimiento, porque
        // eso depende del estado del producto y no solo del request. Con
        // 'deactivate' la cantidad se descarta aunque llegue.
        return [
            'quantity' => ['exclude_if:type,deactivate', 'required', 'integer', 'min:0'],
            'type' => ['required', 'string', Rule::in(self::ACTIONS)],
        ];
    }
}
