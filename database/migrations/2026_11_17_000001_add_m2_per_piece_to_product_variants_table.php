<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * m² que cubre una pieza, para pisos que se venden por pieza (unit_type
     * "pieza"): permite capturar m² requeridos y derivar las piezas, como
     * m2_per_box con las cajas. Opcional (null = sin dato). 4 decimales porque
     * una pieza chica cubre poco (ej. 0.0225 m² en un mosaico de 15x15).
     */
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->decimal('m2_per_piece', 8, 4)->nullable()->after('m2_per_box');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('m2_per_piece');
        });
    }
};
