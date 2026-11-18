<?php

namespace Tests\Support;

use App\Models\Customer;

/**
 * Todo presupuesto lleva cliente (StoreQuoteRequest). Los tests que no
 * prueban nada del cliente usan este, creado una sola vez por test.
 */
trait CreatesQuoteCustomers
{
    private ?int $quoteCustomerId = null;

    private function quoteCustomerId(): int
    {
        return $this->quoteCustomerId ??= Customer::create(['name' => 'Cliente de prueba'])->id;
    }
}
