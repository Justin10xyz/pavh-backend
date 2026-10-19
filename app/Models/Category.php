<?php

namespace App\Models;

use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $code_prefix
 * @property string $product_form_type
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, Product> $products
 * @property-read int|null $products_count
 * @property-read Collection<int, SimpleProduct> $simpleProducts
 * @property-read int|null $simple_products_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Category newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Category newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Category onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Category query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Category whereCodePrefix($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Category whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Category whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Category whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Category whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Category whereProductFormType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Category whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Category withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Category withoutTrashed()
 *
 * @mixin \Eloquent
 */
#[Fillable(['name', 'code_prefix', 'product_form_type'])]
class Category extends Model
{
    use SerializesDatesInAppTimezone, SoftDeletes;

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

    // Pisos y productos generales; los dados de baja (soft delete) no cuentan.
    public function activeProductsCount(): int
    {
        return $this->products()->count() + $this->simpleProducts()->count();
    }

    // Solo bloquean los productos activos: los dados de baja no se ven en el
    // inventario, y la categoría (soft delete) sigue existiendo para ellos.
    public function deletionBlockedMessage(): ?string
    {
        $count = $this->activeProductsCount();

        if ($count === 0) {
            return null;
        }

        $detail = $count === 1
            ? 'tiene 1 producto asociado. Muévelo a otra categoría'
            : "tiene {$count} productos asociados. Muévelos a otra categoría";

        return "No se puede eliminar la categoría \"{$this->name}\": {$detail} antes de eliminarla.";
    }
}
