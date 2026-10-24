<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Agrega el estado Pagada y pasa a él las cotizaciones ya pagadas
     * (incluidas las dadas de baja), que hasta ahora seguían en Borrador.
     * Solo inserta el estado si el catálogo ya fue sembrado: en una BD nueva
     * lo crea QuoteStatusSeeder, igual que los demás.
     */
    public function up(): void
    {
        if (! DB::table('quote_statuses')->exists()) {
            return;
        }

        DB::table('quote_statuses')->insertOrIgnore([
            'name' => 'Pagada',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $paidStatusId = DB::table('quote_statuses')->where('name', 'Pagada')->value('id');

        DB::table('quotes')
            ->whereNotNull('paid_at')
            ->update(['quote_status_id' => $paidStatusId]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $paidStatusId = DB::table('quote_statuses')->where('name', 'Pagada')->value('id');

        if ($paidStatusId === null) {
            return;
        }

        $draftStatusId = DB::table('quote_statuses')->where('name', 'Borrador')->value('id');

        DB::table('quotes')
            ->where('quote_status_id', $paidStatusId)
            ->update(['quote_status_id' => $draftStatusId]);

        DB::table('quote_statuses')->where('id', $paidStatusId)->delete();
    }
};
