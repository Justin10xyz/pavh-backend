<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Abonos: pagos parciales posteriores de una cotización, que se restan de
     * su saldo junto con el anticipo (Quote::balance()). No es "marcar como
     * pagada" (paid_at, que descuenta stock). Un abono anulado queda con
     * soft delete y deja de contar. El índice (quote_id, payment_date) sirve
     * para listarlos por cotización en orden y para filtrar por fecha.
     */
    public function up(): void
    {
        Schema::create('quote_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 10, 2);
            $table->date('payment_date');
            $table->string('notes', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['quote_id', 'payment_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quote_installments');
    }
};
