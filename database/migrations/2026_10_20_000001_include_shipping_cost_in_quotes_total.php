<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * quotes.total pasa de subtotal - descuento a subtotal - descuento + envío.
     * Las cotizaciones existentes (incluidas las dadas de baja) se ajustan para
     * que balance(), que ya no suma el envío por su cuenta, dé el mismo saldo.
     * No toca comprobantes: los ya emitidos conservan el total que copiaron.
     */
    public function up(): void
    {
        DB::table('quotes')
            ->whereNotNull('shipping_cost')
            ->update(['total' => DB::raw('total + shipping_cost')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('quotes')
            ->whereNotNull('shipping_cost')
            ->update(['total' => DB::raw('total - shipping_cost')]);
    }
};
