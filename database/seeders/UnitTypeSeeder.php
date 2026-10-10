<?php

namespace Database\Seeders;

use App\Models\UnitType;
use Illuminate\Database\Seeder;

class UnitTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (['m2', 'pieza'] as $name) {
            UnitType::updateOrCreate(['name' => $name]);
        }
    }
}
