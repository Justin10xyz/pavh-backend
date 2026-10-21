<?php

namespace App\Services;

use App\Models\Comprobante;

class ComprobanteFolioGenerator
{
    private const PREFIX = 'CF-';

    /**
     * Genera el siguiente folio único de comprobante: CF-0001, CF-0002, ...
     */
    public function generate(): string
    {
        $lastFolio = Comprobante::withTrashed()
            ->where('folio', 'like', self::PREFIX.'%')
            ->orderByRaw('CAST(SUBSTRING(folio, ?) AS UNSIGNED) DESC', [strlen(self::PREFIX) + 1])
            ->value('folio');

        $nextNumber = $lastFolio ? ((int) substr($lastFolio, strlen(self::PREFIX)) + 1) : 1;

        return self::PREFIX.str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
    }
}
