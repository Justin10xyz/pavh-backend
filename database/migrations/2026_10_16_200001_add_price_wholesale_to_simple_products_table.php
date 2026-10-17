<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Precio de mayoreo opcional. Sin default: null significa que el producto
     * no se vende a mayoreo (no "precio cero"). Solo se usa cuando el vendedor
     * lo elige en la línea (`price_type`); no hay umbral de cantidad.
     */
    public function up(): void
    {
        Schema::table('simple_products', function (Blueprint $table) {
            $table->decimal('price_wholesale', 10, 2)->nullable()->after('price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('simple_products', function (Blueprint $table) {
            $table->dropColumn('price_wholesale');
        });
    }
};
