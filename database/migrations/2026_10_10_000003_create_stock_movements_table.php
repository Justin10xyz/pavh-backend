<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Arco exclusivo: cada movimiento apunta a una variante O a un producto
     * simple, igual que quote_items/sale_items. La exclusividad se valida en
     * la capa de aplicación, no a nivel columna.
     *
     * type ('add'/'subtract') y source ('manual'/'sale') son strings fijos, no
     * catálogos de negocio, así que no tienen tabla propia.
     */
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('simple_product_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('type');
            $table->string('source');
            $table->foreignId('sale_id')->nullable()->constrained()->restrictOnDelete();
            $table->integer('resulting_stock');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
