<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Sin índice unique en `name`: con soft delete un estilo dado de baja
     * conserva su fila, y su nombre debe poder reusarse. La unicidad se valida
     * en los Form Requests contra los estilos activos (igual que proveedores).
     */
    public function up(): void
    {
        Schema::create('floor_styles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('floor_styles');
    }
};
