<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Domicilio y contacto del negocio, impresos en el encabezado del
     * comprobante. Todo nullable, igual que el resto del registro.
     */
    public function up(): void
    {
        Schema::table('business_settings', function (Blueprint $table) {
            $table->string('address')->nullable()->after('tax_id_number');
            $table->string('neighborhood')->nullable()->after('address');
            $table->string('city')->nullable()->after('neighborhood');
            $table->string('postal_code')->nullable()->after('city');
            $table->string('phone_primary')->nullable()->after('postal_code');
            $table->string('phone_secondary')->nullable()->after('phone_primary');
            $table->string('email')->nullable()->after('phone_secondary');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('business_settings', function (Blueprint $table) {
            $table->dropColumn(['address', 'neighborhood', 'city', 'postal_code', 'phone_primary', 'phone_secondary', 'email']);
        });
    }
};
