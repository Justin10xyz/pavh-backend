<?php

namespace App\Services;

use Carbon\Carbon;

final class SpanishMonthName
{
    private const MONTHS = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
        5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
        9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];

    /**
     * Título del periodo de un reporte: "Octubre 2026" si from y to caen en el
     * mismo mes, o "01/10/2026 – 15/11/2026" si no. El rango no tiene que ser
     * un mes completo (del 5 al 20 de octubre también sale "Octubre 2026").
     */
    public static function periodLabel(Carbon $from, Carbon $to): string
    {
        if ($from->isSameMonth($to) && $from->year === $to->year) {
            return self::MONTHS[$from->month].' '.$from->year;
        }

        return $from->format('d/m/Y').' – '.$to->format('d/m/Y');
    }
}
