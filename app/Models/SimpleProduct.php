<?php

namespace App\Models;

use Database\Factories\SimpleProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'category_id',
    'name',
    'price',
    'description',
    'stock_quantity',
])]
class SimpleProduct extends Model
{
    /** @use HasFactory<SimpleProductFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
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

    public function hasSufficientStock(int $quantity): bool
    {
        return $quantity <= $this->stock_quantity;
    }

    public function decrementStock(int $quantity): void
    {
        $this->decrement('stock_quantity', $quantity);
    }
}
