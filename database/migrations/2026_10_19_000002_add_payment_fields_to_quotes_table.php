<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * discount/down_payment/shipping_cost son nullable sin default: NULL = no
     * aplica. `payment_terms` (cash|credit) es un valor del sistema que decide
     * la lógica de pago, no un catálogo de negocio, así que va como string
     * validado en los Form Requests (sin enum de MySQL), igual que `price_type`.
     */
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->decimal('discount', 10, 2)->nullable()->after('subtotal');
            $table->decimal('down_payment', 10, 2)->nullable()->after('discount');
            $table->decimal('shipping_cost', 10, 2)->nullable()->after('down_payment');
            $table->string('payment_terms', 20)->default('cash')->after('total');
            $table->date('credit_due_date')->nullable()->after('payment_terms');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn(['discount', 'down_payment', 'shipping_cost', 'payment_terms', 'credit_due_date']);
        });
    }
};
