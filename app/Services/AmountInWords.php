<?php

namespace App\Services;

class AmountInWords
{
    private const UNITS = [
        '', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve',
        'diez', 'once', 'doce', 'trece', 'catorce', 'quince', 'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve',
        'veinte', 'veintiuno', 'veintidós', 'veintitrés', 'veinticuatro', 'veinticinco', 'veintiséis', 'veintisiete', 'veintiocho', 'veintinueve',
    ];

    private const TENS = [3 => 'treinta', 4 => 'cuarenta', 5 => 'cincuenta', 6 => 'sesenta', 7 => 'setenta', 8 => 'ochenta', 9 => 'noventa'];

    private const HUNDREDS = [
        1 => 'ciento', 2 => 'doscientos', 3 => 'trescientos', 4 => 'cuatrocientos', 5 => 'quinientos',
        6 => 'seiscientos', 7 => 'setecientos', 8 => 'ochocientos', 9 => 'novecientos',
    ];

    /**
     * Importe en letras para documentos en pesos: "tres mil quinientos pesos
     * 00/100 M.N.". Los centavos se redondean a 2 decimales.
     */
    public function pesos(float|string $amount): string
    {
        $cents = (int) round((float) $amount * 100);
        $integer = intdiv($cents, 100);
        $fraction = str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);

        if ($integer === 0) {
            $words = 'cero pesos';
        } elseif ($integer === 1) {
            $words = 'un peso';
        } else {
            $words = $this->apocope($this->integerToWords($integer));
            // "un millón de pesos", "dos millones de pesos": millones exactos
            // llevan "de".
            $words .= $integer % 1_000_000 === 0 ? ' de pesos' : ' pesos';
        }

        return "{$words} {$fraction}/100 M.N.";
    }

    private function integerToWords(int $number): string
    {
        $millions = intdiv($number, 1_000_000);
        $thousands = intdiv($number % 1_000_000, 1000);
        $rest = $number % 1000;

        $parts = [];

        if ($millions === 1) {
            $parts[] = 'un millón';
        } elseif ($millions > 1) {
            $parts[] = $this->apocope($this->integerToWords($millions)).' millones';
        }

        if ($thousands === 1) {
            $parts[] = 'mil';
        } elseif ($thousands > 1) {
            $parts[] = $this->apocope($this->belowThousand($thousands)).' mil';
        }

        if ($rest > 0) {
            $parts[] = $this->belowThousand($rest);
        }

        return implode(' ', $parts);
    }

    private function belowThousand(int $number): string
    {
        if ($number === 100) {
            return 'cien';
        }

        $hundreds = intdiv($number, 100);
        $rest = $number % 100;

        $parts = [];

        if ($hundreds > 0) {
            $parts[] = self::HUNDREDS[$hundreds];
        }

        if ($rest >= 30) {
            $units = $rest % 10;
            $parts[] = self::TENS[intdiv($rest, 10)].($units > 0 ? ' y '.self::UNITS[$units] : '');
        } elseif ($rest > 0) {
            $parts[] = self::UNITS[$rest];
        }

        return implode(' ', $parts);
    }

    /**
     * "uno" pierde la o antes de un sustantivo: "veintiún mil", "treinta y un
     * pesos".
     */
    private function apocope(string $words): string
    {
        if (str_ends_with($words, 'veintiuno')) {
            return substr($words, 0, -strlen('veintiuno')).'veintiún';
        }

        if (str_ends_with($words, 'uno')) {
            return substr($words, 0, -strlen('uno')).'un';
        }

        return $words;
    }
}
