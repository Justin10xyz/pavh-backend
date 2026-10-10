<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'code_prefix', 'product_form_type'])]
class Category extends Model
{
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function simpleProducts(): HasMany
    {
        return $this->hasMany(SimpleProduct::class);
    }

    public function usesSimpleProductForm(): bool
    {
        return $this->product_form_type === 'simple';
    }

    // Cambiar el tipo dejaría huérfanos los productos del tipo actual (el form del
    // otro tipo no sabe editarlos), así que solo se permite si no tiene ninguno.
    // Los productos dados de baja (soft delete) no cuentan.
    public function productFormTypeChangeBlockedMessage(string $newType): ?string
    {
        if ($newType === $this->product_form_type) {
            return null;
        }

        [$count, $singular, $plural, $targetForm] = $this->usesSimpleProductForm()
            ? [$this->simpleProducts()->count(), 'producto general', 'productos generales', 'Pisos (con variantes)']
            : [$this->products()->count(), 'producto con variantes', 'productos con variantes', 'General'];

        if ($count === 0) {
            return null;
        }

        $detail = $count === 1
            ? "tiene 1 {$singular} asociado, que ya no podría editarse con el formulario {$targetForm}. Muévelo a otra categoría o dalo de baja"
            : "tiene {$count} {$plural} asociados, que ya no podrían editarse con el formulario {$targetForm}. Muévelos a otra categoría o dalos de baja";

        return "No se puede cambiar el tipo de formulario de la categoría \"{$this->name}\": {$detail} antes de cambiar el tipo.";
    }
}
