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

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }
}
