<?php

namespace App\Models;

use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * @property int $id
 * @property int|null $product_variant_id
 * @property int|null $simple_product_id
 * @property int $quantity
 * @property string $type
 * @property string $source
 * @property int|null $sale_id
 * @property int|null $resulting_stock
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\ProductVariant|null $productVariant
 * @property-read \App\Models\Sale|null $sale
 * @property-read \App\Models\SimpleProduct|null $simpleProduct
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockMovement newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockMovement newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockMovement query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockMovement whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockMovement whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockMovement whereProductVariantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockMovement whereQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockMovement whereResultingStock($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockMovement whereSaleId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockMovement whereSimpleProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockMovement whereSource($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockMovement whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StockMovement whereUpdatedAt($value)
 * @mixin \Eloquent
 */
#[Fillable([
    'product_variant_id',
    'simple_product_id',
    'quantity',
    'type',
    'source',
    'sale_id',
    'resulting_stock',
])]
class StockMovement extends Model
{
    use SerializesDatesInAppTimezone;

    public const TYPES = ['add', 'subtract'];

    /**
     * 'initial' es el primer ajuste manual de un producto sin seguimiento de
     * stock: lo activa partiendo de 0. 'deactivated' es lo inverso: retira
     * todo el stock que tenía (type 'subtract') y lo deja sin seguimiento.
     */
    public const SOURCES = ['initial', 'manual', 'sale', 'deactivated'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'resulting_stock' => 'integer',
        ];
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function simpleProduct(): BelongsTo
    {
        return $this->belongsTo(SimpleProduct::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * Único punto que muta stock_boxes/stock_quantity: aplica el movimiento
     * y registra su historial en la misma transacción, así no puede existir
     * un cambio de stock sin su fila correspondiente. La validación de stock
     * suficiente es responsabilidad del llamador.
     *
     * Un producto sin seguimiento (stock null) solo admite un movimiento
     * 'add' con origen 'initial', que inicializa el stock en 0 antes de
     * sumar; y 'initial' solo vale para ese caso.
     *
     * 'deactivated' es la única salida del seguimiento: solo sobre un
     * producto que lo lleva, como 'subtract' de exactamente su stock actual
     * (el historial guarda cuánto tenía), y deja el stock y el
     * resulting_stock en null.
     *
     * La tabla no restringe el arco exclusivo (variante O producto simple) a
     * nivel de base de datos, así que esta validación es la única barrera.
     */
    public static function record(
        ProductVariant|SimpleProduct $stockable,
        string $type,
        int $quantity,
        string $source,
        ?Sale $sale = null,
    ): self {
        if (! in_array($type, self::TYPES, true)) {
            throw new LogicException("Tipo de movimiento de stock inválido: {$type}.");
        }

        if (! in_array($source, self::SOURCES, true)) {
            throw new LogicException("Origen de movimiento de stock inválido: {$source}.");
        }

        // El movimiento inicial puede ser 0: activa el seguimiento sin
        // existencias (ej. un producto dado de alta con stock 0). La
        // desactivación también, si el producto no tenía existencias.
        if ($quantity < 0 || ($quantity === 0 && ! in_array($source, ['initial', 'deactivated'], true))) {
            throw new LogicException('La cantidad de un movimiento de stock debe ser positiva.');
        }

        $column = $stockable instanceof ProductVariant ? 'stock_boxes' : 'stock_quantity';

        // Antes que las demás reglas de seguimiento: sobre un producto sin
        // seguimiento chocaría con la de 'initial' con un mensaje engañoso.
        if ($source === 'deactivated') {
            if (! $stockable->tracksStock()) {
                throw new LogicException('No se puede desactivar el seguimiento de stock de un producto que no lo lleva.');
            }

            if ($type !== 'subtract' || $quantity !== $stockable->{$column}) {
                throw new LogicException('Desactivar el seguimiento de stock retira exactamente el stock actual.');
            }
        }

        if (! $stockable->tracksStock() && $type === 'subtract') {
            throw new LogicException('No se puede restar stock a un producto sin seguimiento de stock.');
        }

        if (($source === 'initial') === $stockable->tracksStock()) {
            throw new LogicException('Un movimiento de stock es inicial si y solo si el producto no lleva seguimiento de stock.');
        }

        if (($source === 'sale') !== ($sale !== null)) {
            throw new LogicException('Un movimiento de stock lleva venta si y solo si su origen es una venta.');
        }

        $attributes = [
            'product_variant_id' => $stockable instanceof ProductVariant ? $stockable->id : null,
            'simple_product_id' => $stockable instanceof SimpleProduct ? $stockable->id : null,
        ];

        if (($attributes['product_variant_id'] === null) === ($attributes['simple_product_id'] === null)) {
            throw new LogicException('Un movimiento de stock debe apuntar exactamente a una variante o a un producto simple.');
        }

        return DB::transaction(function () use ($stockable, $type, $quantity, $source, $sale, $attributes, $column) {
            // increment() sobre NULL deja NULL en SQL: se parte de 0.
            if ($source === 'initial') {
                $stockable->update([$column => 0]);
            }

            if ($source === 'deactivated') {
                $stockable->update([$column => null]);
            } else {
                $type === 'add'
                    ? $stockable->increment($column, $quantity)
                    : $stockable->decrement($column, $quantity);
            }

            return self::create([
                ...$attributes,
                'quantity' => $quantity,
                'type' => $type,
                'source' => $source,
                'sale_id' => $sale?->id,
                'resulting_stock' => $stockable->{$column},
            ]);
        });
    }
}
