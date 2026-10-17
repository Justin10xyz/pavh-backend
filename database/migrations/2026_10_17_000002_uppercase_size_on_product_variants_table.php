<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * La medida se guarda con "X" mayúscula (ej. "60X60"), igual que el
     * segmento de medida del código (VariantCodeGenerator ya la normaliza),
     * así que los códigos existentes no cambian. Mezclar "60x60" y "60X60"
     * separaba grupos en groupVariants.js (agrupa por medida exacta).
     *
     * Incluye variantes borradas (soft delete) para que una restaurada no
     * regrese con el formato viejo.
     */
    public function up(): void
    {
        DB::table('product_variants')
            ->select('id', 'size')
            ->orderBy('id')
            ->get()
            ->filter(fn ($row) => $row->size !== strtoupper($row->size))
            ->each(fn ($row) => DB::table('product_variants')
                ->where('id', $row->id)
                ->update(['size' => strtoupper($row->size)]));
    }

    /**
     * Reverse the migrations.
     *
     * Sin reversa: no hay forma de saber qué medidas estaban en minúscula.
     */
    public function down(): void
    {
        //
    }
};
