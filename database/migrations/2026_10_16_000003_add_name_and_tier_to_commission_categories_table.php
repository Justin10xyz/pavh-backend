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
        Schema::table('commission_categories', function (Blueprint $table) {
            // Nombre visible del código (ej. "Verde oscuro" para Vo).
            $table->string('name')->nullable()->after('code');
            // Nivel de comisión: 1 = la que más le deja al vendedor (Vo), números
            // mayores = menos comisión (R). Ordena el catálogo y decide el color en
            // el frontend. Nullable: las filas existentes lo reciben del seeder.
            $table->unsignedTinyInteger('tier')->nullable()->after('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('commission_categories', function (Blueprint $table) {
            $table->dropColumn(['name', 'tier']);
        });
    }
};
