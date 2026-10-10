<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Arco exclusivo: cada línea apunta a una variante O a un producto simple.
     * La exclusividad se valida en los Form Requests, no a nivel columna.
     */
    public function up(): void
    {
        foreach (['quote_items', 'sale_items'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedBigInteger('product_variant_id')->nullable()->change();

                $table->foreignId('simple_product_id')
                    ->nullable()
                    ->after('product_variant_id')
                    ->constrained()
                    ->restrictOnDelete();
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
                $table->dropConstrainedForeignId('simple_product_id');

                $table->unsignedBigInteger('product_variant_id')->nullable(false)->change();
            });
        }
    }
};
