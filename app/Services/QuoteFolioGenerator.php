<?php

namespace App\Services;

use App\Models\Quote;

class QuoteFolioGenerator
{
    private const PREFIX = 'COT-';

    /**
     * Genera el siguiente folio único de cotización: COT-0001, COT-0002, ...
     */
    public function generate(): string
    {
        $lastFolio = Quote::withTrashed()
            ->where('folio', 'like', self::PREFIX.'%')
            ->orderByRaw('CAST(SUBSTRING(folio, ?) AS UNSIGNED) DESC', [strlen(self::PREFIX) + 1])
            ->value('folio');

        $nextNumber = $lastFolio ? ((int) substr($lastFolio, strlen(self::PREFIX)) + 1) : 1;

        return self::PREFIX.str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
    }
}
