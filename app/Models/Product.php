<?php

namespace App\Models;

use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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
