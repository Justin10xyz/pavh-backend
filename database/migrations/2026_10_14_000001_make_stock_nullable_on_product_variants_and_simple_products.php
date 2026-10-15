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
     * El stock pasa a ser opcional: null significa "este producto no se
     * trackea" (sin alertas, sin validación de suficiencia, sin movimientos).
     * Se quita el default 0 para que un producto nuevo sin stock capturado
     * quede sin trackear en vez de aparentar 0 existencias. Los registros
     * existentes conservan su valor actual.
     */
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->integer('stock_boxes')->nullable()->default(null)->change();
        });

        Schema::table('simple_products', function (Blueprint $table) {
            $table->integer('stock_quantity')->nullable()->default(null)->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Los productos sin trackear vuelven a 0 antes de restaurar el NOT NULL.
     */
    public function down(): void
    {
        DB::table('product_variants')->whereNull('stock_boxes')->update(['stock_boxes' => 0]);
        DB::table('simple_products')->whereNull('stock_quantity')->update(['stock_quantity' => 0]);

        Schema::table('product_variants', function (Blueprint $table) {
            $table->integer('stock_boxes')->default(0)->change();
        });

        Schema::table('simple_products', function (Blueprint $table) {
            $table->integer('stock_quantity')->default(0)->change();
        });
    }
};
