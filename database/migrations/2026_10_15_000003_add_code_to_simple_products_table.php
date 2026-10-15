<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Código capturado a mano por ahora (falta decidir si se genera solo, como
     * en variantes). Opcional: los productos existentes no tienen; el índice
     * único admite varios null.
     */
    public function up(): void
    {
        Schema::table('simple_products', function (Blueprint $table) {
            $table->string('code', 50)->nullable()->unique()->after('category_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('simple_products', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }
};
