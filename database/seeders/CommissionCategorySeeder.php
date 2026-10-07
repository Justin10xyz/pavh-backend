<?php

namespace Database\Seeders;

use App\Models\CommissionCategory;
use Illuminate\Database\Seeder;

class CommissionCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (['Vo', 'N', 'A'] as $code) {
            CommissionCategory::updateOrCreate(['code' => $code]);
        }
    }
}
