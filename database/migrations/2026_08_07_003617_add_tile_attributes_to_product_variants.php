<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->foreignId('commission_category_id')
                ->nullable()
                ->after('supplier_code')
                ->constrained('commission_categories')
                ->nullOnDelete();

            $table->string('pei', 10)->nullable()->after('commission_category_id');
            $table->unsignedTinyInteger('ett')->nullable()->after('pei');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('commission_category_id');
            $table->dropColumn(['pei', 'ett']);
        });
    }
};
