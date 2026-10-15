<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Un movimiento 'deactivated' deja el producto sin seguimiento de stock:
     * su resultado ya no es un número, así que resulting_stock pasa a ser
     * opcional. Es el único origen que lo deja en null.
     */
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->integer('resulting_stock')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Las desactivaciones registradas quedan con resultado 0 antes de
     * restaurar el NOT NULL.
     */
    public function down(): void
    {
        DB::table('stock_movements')->whereNull('resulting_stock')->update(['resulting_stock' => 0]);

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->integer('resulting_stock')->nullable(false)->change();
        });
    }
};
