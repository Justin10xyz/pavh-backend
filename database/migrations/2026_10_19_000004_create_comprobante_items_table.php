<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Misma forma que quote_items/sale_items: arco exclusivo variante | producto
     * simple (validado en los Form Requests), `boxes` como cantidad real en
     * variantes y `quantity` con los m² derivados (o unidades en simples).
     */
    public function up(): void
    {
        Schema::create('comprobante_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comprobante_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('simple_product_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('boxes')->nullable();
            $table->decimal('quantity', 10, 3);
            $table->decimal('unit_price', 10, 2);
            $table->string('price_type', 20)->default('retail');
            $table->decimal('line_total', 10, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comprobante_items');
    }
};
