<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Datos fijos del negocio para el comprobante (no cambian por documento).
     * Registro único (id 1, ver BusinessSetting::current()), no un catálogo.
     * Todo nullable: el registro se crea vacío y se llena desde Configuración.
     */
    public function up(): void
    {
        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('place_of_issue')->nullable();
            $table->string('business_regime')->nullable();
            // RFC de la Cédula de Identificación Fiscal.
            $table->string('tax_id')->nullable();
            // idCIF de la Cédula de Identificación Fiscal.
            $table->string('tax_id_number')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_settings');
    }
};
