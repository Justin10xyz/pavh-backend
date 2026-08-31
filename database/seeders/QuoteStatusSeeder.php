<?php

namespace Database\Seeders;

use App\Models\QuoteStatus;
use Illuminate\Database\Seeder;

class QuoteStatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (['Borrador', 'Convertida', 'Cancelada'] as $name) {
            QuoteStatus::updateOrCreate(['name' => $name]);
        }
    }
}
