<?php

namespace App\Models;

use App\Models\Concerns\SerializesDatesInAppTimezone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use LogicException;

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
     * stock: lo activa partiendo de 0.
     */
    public const SOURCES = ['initial', 'manual', 'sale'];

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

        if ($quantity <= 0) {
            throw new LogicException('La cantidad de un movimiento de stock debe ser positiva.');
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

        $column = $stockable instanceof ProductVariant ? 'stock_boxes' : 'stock_quantity';

        return DB::transaction(function () use ($stockable, $type, $quantity, $source, $sale, $attributes, $column) {
            // increment() sobre NULL deja NULL en SQL: se parte de 0.
            if ($source === 'initial') {
                $stockable->update([$column => 0]);
            }

            $type === 'add'
                ? $stockable->increment($column, $quantity)
                : $stockable->decrement($column, $quantity);

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
