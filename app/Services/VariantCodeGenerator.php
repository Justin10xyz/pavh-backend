<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;

class VariantCodeGenerator
{
    /**
     * Genera el código único de variante: [PREFIJO_CATEGORIA]-[LINEA]-[COLOR]-[MEDIDA]
     */
    public function generate(Product $product, string $color, string $size): string
    {
        $prefix = $product->category->code_prefix;
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

        return strtoupper(substr($firstWord, 0, $length));
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
