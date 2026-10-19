<?php

namespace App\Models;

use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $supplier_id
 * @property int $category_id
 * @property int $unit_type_id
 * @property string $name
 * @property string $purchase_unit
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Category|null $category
 * @property-read Supplier|null $supplier
 * @property-read UnitType $unitType
 * @property-read Collection<int, ProductVariant> $variants
 * @property-read int|null $variants_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereCategoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product wherePurchaseUnit($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereSupplierId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereUnitTypeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product withoutTrashed()
 *
 * @mixin \Eloquent
 */
#[Fillable(['supplier_id', 'category_id', 'unit_type_id', 'name', 'purchase_unit'])]
class Product extends Model
{
    use SerializesDatesInAppTimezone, SoftDeletes;

    // withTrashed: un producto dado de baja puede seguir con un proveedor ya
    // eliminado (solo se bloquea con productos activos).
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    // withTrashed: un producto dado de baja puede seguir en una categoría ya
    // eliminada (solo se bloquea con productos activos).
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class)->withTrashed();
    }

    public function unitType(): BelongsTo
    {
        return $this->belongsTo(UnitType::class);
    }

    /**
     * Pisos con unit_type "pieza" (ej. mosaicos/decorados): se venden y
     * cobran por pieza (price_per_box), sin m².
     */
    public function isSoldByPiece(): bool
    {
        $this->loadMissing('unitType');

        return $this->unitType->name === 'pieza';
    }

    // Cambiar la unidad dejaría a las variantes con datos de la unidad anterior
    // (price_per_m2/m2_per_box/pei/ett en una "pieza", o sin ellos en una "m2"),
    // así que solo se permite sin variantes. Las dadas de baja (soft delete) no cuentan.
    public function unitTypeChangeBlockedMessage(int $newUnitTypeId): ?string
    {
        if ($newUnitTypeId === (int) $this->unit_type_id) {
            return null;
        }

        $count = $this->variants()->count();

        if ($count === 0) {
            return null;
        }

        $detail = $count === 1 ? '1 variante registrada' : "{$count} variantes registradas";

        return "No se puede cambiar la unidad de venta: el producto ya tiene {$detail}.";
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }
}
