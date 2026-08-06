<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained();
            $table->string('code')->unique();
            $table->string('supplier_code')->nullable();
            $table->string('color');
            $table->string('size');
            $table->decimal('price_per_m2', 10, 2);
            $table->decimal('price_per_box', 10, 2);
            $table->integer('pieces_per_box')->nullable();
            $table->decimal('m2_per_box', 8, 3)->nullable();
            $table->decimal('kilos_per_box', 8, 2)->nullable();
            $table->integer('boxes_per_pallet')->nullable();
            $table->integer('stock_boxes')->default(0);
            $table->integer('minimum_stock')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
