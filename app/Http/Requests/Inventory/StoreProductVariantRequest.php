<?php

namespace App\Http\Requests\Inventory;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductVariantRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * La medida se guarda sin espacios y con "X" mayúscula (ej. "60 x 60" →
     * "60X60"): groupVariants.js agrupa por medida exacta y cualquier otra
     * escritura separaría el grupo.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->size)) {
            $this->merge(['size' => mb_strtoupper(preg_replace('/\s+/u', '', $this->size))]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Las reglas de precio/atributos dependen del unit_type del producto
     * padre (product_id del mismo payload). Un piso por pieza solo lleva
     * price_per_box (el precio por pieza); price_per_m2, m2_per_box, pei y
     * ett no aplican y se prohíben para no guardar datos sin sentido.
     * face_count sí aplica a ambos tipos.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $soldByPiece = $this->productIsSoldByPiece();

        return [
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->withoutTrashed()],
            'color' => ['required', 'string'],
            'size' => ['required', 'string'],
            'price_per_m2' => $soldByPiece ? ['prohibited'] : ['required', 'numeric'],
            'price_per_box' => ['required', 'numeric'],
            'pieces_per_box' => ['nullable', 'integer'],
            'm2_per_box' => $soldByPiece ? ['prohibited'] : ['nullable', 'numeric'],
            'kilos_per_box' => ['nullable', 'numeric'],
            'boxes_per_pallet' => ['nullable', 'integer'],
            'stock_boxes' => ['nullable', 'integer', 'min:0'],
            'minimum_stock' => ['nullable', 'integer'],
            'commission_category_id' => ['nullable', 'exists:commission_categories,id'],
            'pei' => $soldByPiece ? ['prohibited'] : ['nullable', Rule::in(ProductVariant::PEI_VALUES)],
            'ett' => $soldByPiece ? ['prohibited'] : ['nullable', 'integer', 'between:1,4'],
            'face_count' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * Un product_id inválido o inexistente cae en las reglas de m2 (las de
     * siempre): su error ya lo reporta el exists de product_id.
     */
    private function productIsSoldByPiece(): bool
    {
        $productId = $this->input('product_id');

        if (! is_numeric($productId)) {
            return false;
        }

        return Product::withTrashed()->find($productId)?->isSoldByPiece() ?? false;
    }
}
