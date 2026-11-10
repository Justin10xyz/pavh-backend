<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VariantCodeGenerator
{
    /**
     * Genera el código único de variante: [PREFIJO_CATEGORIA]-[LINEA]-[COLOR]-[MEDIDA]
     */
    public function generate(Product $product, string $color, string $size): string
    {
        $prefix = $product->category->code_prefix;

        // El prefijo es opcional en categorías General; si una de Pisos llegara
        // a quedar sin él, mejor un 422 claro que un código "-LINEA-COLOR-MEDIDA".
        if ($prefix === null || $prefix === '') {
            throw ValidationException::withMessages([
                'product_id' => "La categoría \"{$product->category->name}\" no tiene prefijo de código. Captúralo en Configuración → Categorías antes de agregar variantes.",
            ]);
        }

        $line = $this->normalizeLine($product->name);
        $sizeSegment = $this->normalizeSize($size);

        foreach ([3, 4] as $colorLength) {
            $colorSegment = $this->normalizeColor($color, $colorLength);
            $code = "{$prefix}-{$line}-{$colorSegment}-{$sizeSegment}";

            if (! $this->codeExists($code)) {
                return $code;
            }
        }

        $colorSegment = $this->normalizeColor($color, 3);
        $suffix = 2;

        do {
            $code = "{$prefix}-{$line}-{$colorSegment}{$suffix}-{$sizeSegment}";
            $suffix++;
        } while ($this->codeExists($code));

        return $code;
    }

    private function normalizeLine(string $name): string
    {
        return strtoupper(str_replace(' ', '', $name));
    }

    private function normalizeColor(string $color, int $length): string
    {
        $firstWord = strtok(trim($color), ' ');

        // Str::ascii antes de cortar: substr() trabaja por bytes y partiría una
        // "ñ" o vocal acentuada, generando un código que MySQL rechaza.
        return strtoupper(substr(Str::ascii($firstWord), 0, $length));
    }

    private function normalizeSize(string $size): string
    {
        return strtoupper(str_replace(' ', '', $size));
    }

    private function codeExists(string $code): bool
    {
        return ProductVariant::withTrashed()->where('code', $code)->exists();
    }
}
