<?php

namespace Tests\Unit\Services;

use App\Services\AmountInWords;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AmountInWordsTest extends TestCase
{
    /**
     * @return array<string, array{float|string, string}>
     */
    public static function amounts(): array
    {
        return [
            'cero' => [0, 'cero pesos 00/100 M.N.'],
            'un peso' => [1, 'un peso 00/100 M.N.'],
            'centavos' => ['0.50', 'cero pesos 50/100 M.N.'],
            'veintiún' => [21, 'veintiún pesos 00/100 M.N.'],
            'treinta y un' => [31.05, 'treinta y un pesos 05/100 M.N.'],
            'cien' => [100, 'cien pesos 00/100 M.N.'],
            'ciento uno' => [101, 'ciento un pesos 00/100 M.N.'],
            'quinientos' => [515.99, 'quinientos quince pesos 99/100 M.N.'],
            'mil' => [1000, 'mil pesos 00/100 M.N.'],
            'ejemplo' => ['3500.00', 'tres mil quinientos pesos 00/100 M.N.'],
            'veintiún mil' => [21000, 'veintiún mil pesos 00/100 M.N.'],
            'ciento un mil' => [101001, 'ciento un mil un pesos 00/100 M.N.'],
            'dieciséis mil' => [16_999.10, 'dieciséis mil novecientos noventa y nueve pesos 10/100 M.N.'],
            'un millón' => [1_000_000, 'un millón de pesos 00/100 M.N.'],
            'millones' => [2_500_000, 'dos millones quinientos mil pesos 00/100 M.N.'],
            'veintiún millones' => [21_000_000, 'veintiún millones de pesos 00/100 M.N.'],
            'máximo' => ['99999999.99', 'noventa y nueve millones novecientos noventa y nueve mil novecientos noventa y nueve pesos 99/100 M.N.'],
        ];
    }

    #[DataProvider('amounts')]
    public function test_it_writes_pesos_in_spanish(float|string $amount, string $expected): void
    {
        $this->assertSame($expected, (new AmountInWords)->pesos($amount));
    }
}
