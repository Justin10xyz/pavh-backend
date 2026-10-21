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
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, Product> $products
 * @property-read int|null $products_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FloorStyle newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FloorStyle newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FloorStyle onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FloorStyle query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FloorStyle whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FloorStyle whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FloorStyle whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FloorStyle whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FloorStyle whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FloorStyle withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FloorStyle withoutTrashed()
 *
 * @mixin \Eloquent
 */
#[Fillable(['name'])]
class FloorStyle extends Model
{
    use SerializesDatesInAppTimezone, SoftDeletes;

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    // Los productos dados de baja (soft delete) no cuentan.
    public function activeProductsCount(): int
    {
        return $this->products()->count();
    }

    // Solo bloquean los productos activos: los dados de baja no se ven en el
    // inventario, y el estilo (soft delete) sigue existiendo para ellos.
    public function deletionBlockedMessage(): ?string
    {
        $count = $this->activeProductsCount();

        if ($count === 0) {
            return null;
        }

        $detail = $count === 1
            ? 'tiene 1 producto asociado. Muévelo a otro estilo de piso'
            : "tiene {$count} productos asociados. Muévelos a otro estilo de piso";

        return "No se puede eliminar el estilo de piso \"{$this->name}\": {$detail} antes de eliminarlo.";
    }
}
