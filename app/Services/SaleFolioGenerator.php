<?php

namespace App\Services;

use App\Models\Sale;

class SaleFolioGenerator
{
    private const PREFIX = 'V-';

    /**
     * Genera el siguiente folio único de venta: V-0001, V-0002, ...
     */
    public function generate(): string
    {
        $lastFolio = Sale::withTrashed()
            ->where('folio', 'like', self::PREFIX.'%')
            ->orderByRaw('CAST(SUBSTRING(folio, ?) AS UNSIGNED) DESC', [strlen(self::PREFIX) + 1])
            ->value('folio');

        $nextNumber = $lastFolio ? ((int) substr($lastFolio, strlen(self::PREFIX)) + 1) : 1;

        return self::PREFIX.str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
    }
}
