<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Un piso que se vende por pieza (unit_type "pieza") solo tiene precio
     * por pieza (guardado en price_per_box); el precio por m² no aplica.
     */
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->decimal('price_per_m2', 10, 2)->nullable()->change();
            $table->decimal('price_per_box', 10, 2)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Falla si hay variantes con algún precio en null: se tienen que capturar
     * antes de restaurar el NOT NULL.
     */
    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->decimal('price_per_m2', 10, 2)->nullable(false)->change();
            $table->decimal('price_per_box', 10, 2)->nullable(false)->change();
        });
    }
};
