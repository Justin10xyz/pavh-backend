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
     * Elimina el módulo de ventas: sus tablas, la FK que les apuntaba desde
     * stock_movements y el estado Convertida, que solo asignaba la conversión
     * de una cotización a venta.
     *
     * Las cotizaciones Convertida (incluidas las dadas de baja) pasan a
     * Pagada: su stock ya se descontó con la venta, así que quedan como si se
     * hubieran pagado en ese momento (paid_at/paid_by de su venta) y no se
     * pueden volver a pagar. Si alguna no tiene venta, se usa su updated_at.
     * Se lee la venta antes de borrar la tabla, y se resuelve en PHP y no con
     * UPDATE … JOIN: los tests corren en SQLite.
     *
     * Los movimientos de stock con origen 'sale' se conservan como historial,
     * solo pierden el enlace a la venta.
     */
    public function up(): void
    {
        $convertedStatusId = DB::table('quote_statuses')->where('name', 'Convertida')->value('id');

        if ($convertedStatusId !== null) {
            DB::table('quote_statuses')->insertOrIgnore([
                'name' => 'Pagada',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $paidStatusId = DB::table('quote_statuses')->where('name', 'Pagada')->value('id');

            DB::table('quotes')
                ->where('quote_status_id', $convertedStatusId)
                ->select('id', 'paid_at', 'paid_by', 'updated_at')
                ->orderBy('id')
                ->get()
                ->each(function ($quote) use ($paidStatusId) {
                    $sale = DB::table('sales')
                        ->where('quote_id', $quote->id)
                        ->orderBy('id')
                        ->first(['created_at', 'created_by']);

                    DB::table('quotes')->where('id', $quote->id)->update([
                        'quote_status_id' => $paidStatusId,
                        'paid_at' => $quote->paid_at ?? $sale?->created_at ?? $quote->updated_at,
                        'paid_by' => $quote->paid_by ?? $sale?->created_by,
                    ]);
                });

            DB::table('quote_statuses')->where('id', $convertedStatusId)->delete();
        }

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sale_id');
        });

        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }

    /**
     * Reverse the migrations.
     *
     * Solo restaura la estructura (tablas vacías, columna sale_id en null y
     * el estado Convertida sin cotizaciones): las ventas borradas no se
     * recuperan, y las cotizaciones que pasaron a Pagada no se distinguen de
     * las pagadas de verdad, así que se quedan en Pagada.
     */
    public function down(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('folio')->unique();
            $table->foreignId('quote_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('subtotal', 10, 2);
            $table->decimal('total', 10, 2);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('simple_product_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('boxes')->nullable();
            $table->decimal('quantity', 10, 3);
            $table->decimal('unit_price', 10, 2);
            $table->string('price_type', 20)->default('retail');
            $table->decimal('line_total', 10, 2);
            $table->timestamps();
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('sale_id')->nullable()->after('source')->constrained()->restrictOnDelete();
        });

        if (DB::table('quote_statuses')->exists()) {
            DB::table('quote_statuses')->insertOrIgnore([
                'name' => 'Convertida',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
