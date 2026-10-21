<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Completa el domicilio del cliente (address/neighborhood/city ya existen).
     * String y no entero: un C.P. puede empezar con cero.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('postal_code', 10)->nullable()->after('city');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('postal_code');
        });
    }
};
