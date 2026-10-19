<?php

namespace App\Models;

use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $name
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Product> $products
 * @property-read int|null $products_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Supplier newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Supplier newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Supplier onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Supplier query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Supplier whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Supplier whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Supplier whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Supplier whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Supplier whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Supplier withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Supplier withoutTrashed()
 * @mixin \Eloquent
 */
#[Fillable(['name'])]
class Supplier extends Model
{
    use SerializesDatesInAppTimezone, SoftDeletes;

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    // Solo pisos: los productos generales no llevan proveedor. Los dados de
    // baja (soft delete) no cuentan.
    public function activeProductsCount(): int
    {
        return $this->products()->count();
    }

    // Solo bloquean los productos activos: los dados de baja no se ven en el
    // inventario, y el proveedor (soft delete) sigue existiendo para ellos.
    public function deletionBlockedMessage(): ?string
    {
        $count = $this->activeProductsCount();

        if ($count === 0) {
            return null;
        }

        $detail = $count === 1
            ? 'tiene 1 producto asociado. Muévelo a otro proveedor'
            : "tiene {$count} productos asociados. Muévelos a otro proveedor";

        return "No se puede eliminar el proveedor \"{$this->name}\": {$detail} antes de eliminarlo.";
    }
}
