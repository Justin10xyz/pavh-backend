<?php

namespace App\Models;

use App\Models\Concerns\SerializesDatesInAppTimezone;
use Database\Factories\SimpleProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'category_id',
    'code',
    'name',
    'price',
    'price_wholesale',
    'description',
    'stock_quantity',
])]
class SimpleProduct extends Model
{
    /** @use HasFactory<SimpleProductFactory> */
    use HasFactory, SerializesDatesInAppTimezone, SoftDeletes;

    /**
     * Valores de `price_type` en las líneas de cotización/venta: qué precio
     * del producto se usó. El vendedor lo elige por línea (sin umbral de
     * cantidad); las líneas de variante siempre quedan en retail.
     */
    public const PRICE_TYPES = ['retail', 'wholesale'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'price_wholesale' => 'decimal:2',
            'stock_quantity' => 'integer',
        ];
    }

    // withTrashed: un producto dado de baja puede seguir en una categoría ya
    // eliminada (solo se bloquea con productos activos).
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class)->withTrashed();
    }

    /**
     * stock_quantity es entero: una cantidad fraccionaria no se puede vender
     * ni descontar. Devuelve el mensaje de rechazo o null si es válida. Usado
     * por cotizaciones y ventas — una sola fuente de verdad.
     */
    public function fractionalQuantityMessage(float|string $quantity): ?string
    {
        if (floor((float) $quantity) == (float) $quantity) {
            return null;
        }

        return "La cantidad de {$this->name} debe ser un número entero de unidades.";
    }

    /**
     * Precio actual según el tipo de línea. null si el producto no tiene ese
     * precio (price_wholesale es opcional) — nunca cae al precio de menudeo.
     */
    public function priceFor(string $priceType): ?string
    {
        return $priceType === 'wholesale' ? $this->price_wholesale : $this->price;
    }

    /**
     * Devuelve el mensaje de rechazo o null si el producto tiene precio para
     * ese tipo de línea. Usado al cotizar y al convertir — una sola fuente de
     * verdad.
     */
    public function missingPriceMessage(string $priceType): ?string
    {
        if ($this->priceFor($priceType) !== null) {
            return null;
        }

        return "El producto {$this->name} no tiene precio de mayoreo configurado.";
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * stock_quantity null significa que el producto no lleva seguimiento de
     * stock: no bloquea ventas ni genera movimientos.
     */
    public function tracksStock(): bool
    {
        return $this->stock_quantity !== null;
    }

    public function hasSufficientStock(int $quantity): bool
    {
        return ! $this->tracksStock() || $quantity <= $this->stock_quantity;
    }
}
