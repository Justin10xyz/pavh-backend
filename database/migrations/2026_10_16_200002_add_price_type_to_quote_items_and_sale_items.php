<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Qué precio del producto simple se usó en la línea (`retail`|`wholesale`).
     * Es un valor del sistema que decide la lógica de precio, no un catálogo
     * de negocio, así que va como string validado en los Form Requests (sin
     * enum de MySQL). Las líneas existentes y las de variante quedan en retail.
     */
    public function up(): void
    {
        foreach (['quote_items', 'sale_items'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('price_type', 20)->default('retail')->after('unit_price');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['quote_items', 'sale_items'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('price_type');
            });
        }
    }
};
