<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * El PEI pasa a un catálogo cerrado en romano (ProductVariant::PEI_VALUES).
     * Los valores en arábigo, simples ("4") o en rango ("3/4"), se convierten
     * a su equivalente romano ("IV", "III/IV"). Los que ya están en romano y
     * los null (pisos por pieza) no se tocan.
     *
     * Se calcula en PHP y no con REPLACE en SQL: los tests corren en SQLite.
     */
    public function up(): void
    {
        $roman = ['1' => 'I', '2' => 'II', '3' => 'III', '4' => 'IV', '5' => 'V'];

        DB::table('product_variants')
            ->whereNotNull('pei')
            ->select('id', 'pei')
            ->orderBy('id')
            ->get()
            ->each(function ($row) use ($roman) {
                $parts = explode('/', str_replace(' ', '', $row->pei));

                if (! collect($parts)->every(fn ($part) => isset($roman[$part]))) {
                    return;
                }

                $pei = implode('/', array_map(fn ($part) => $roman[$part], $parts));

                DB::table('product_variants')->where('id', $row->id)->update(['pei' => $pei]);
            });
    }

    /**
     * Reverse the migrations.
     *
     * Sin reversa: no hay forma de saber qué variantes estaban en arábigo y
     * cuáles ya venían en romano.
     */
    public function down(): void
    {
        //
    }
};
