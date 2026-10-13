<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Las variantes se venden por caja completa: `boxes` pasa a ser la cantidad
     * real de una línea de variante (stock y cobro), y `quantity` queda como los
     * m² que cubren esas cajas (boxes × m2_per_box), guardados como foto del
     * momento. En líneas de producto simple `boxes` queda en null y `quantity`
     * sigue siendo la cantidad en unidades.
     *
     * `quantity` pasa a 3 decimales porque m2_per_box tiene 3 (ej. 1.125 × 3 =
     * 3.375 m²); con 2 se perdería precisión al derivarla.
     */
    public function up(): void
    {
        foreach (['quote_items', 'sale_items'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedInteger('boxes')->nullable()->after('simple_product_id');
                $table->decimal('quantity', 10, 3)->change();
            });

            // Líneas existentes: las cajas que ya se descontaban/cotizaban con el
            // criterio anterior (ceil). No se recalculan quantity ni montos: son
            // documentos ya emitidos. Sin m2_per_box no hay forma de saberlo y la
            // línea queda en null.
            //
            // Se calcula en PHP y no con UPDATE … JOIN / CEIL en SQL: los tests
            // corren en SQLite, que no soporta ninguno de los dos de forma portable.
            DB::table($tableName)
                ->join('product_variants', 'product_variants.id', '=', "{$tableName}.product_variant_id")
                ->where('product_variants.m2_per_box', '>', 0)
                ->select("{$tableName}.id", "{$tableName}.quantity", 'product_variants.m2_per_box')
                ->orderBy("{$tableName}.id")
                ->get()
                ->each(fn ($row) => DB::table($tableName)
                    ->where('id', $row->id)
                    ->update(['boxes' => (int) ceil(round($row->quantity / $row->m2_per_box, 6))]));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['quote_items', 'sale_items'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('boxes');
                $table->decimal('quantity', 10, 2)->change();
            });
        }
    }
};
