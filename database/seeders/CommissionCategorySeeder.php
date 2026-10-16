<?php

namespace Database\Seeders;

use App\Models\CommissionCategory;
use Illuminate\Database\Seeder;

class CommissionCategorySeeder extends Seeder
{
    /**
     * Códigos de comisión del vendedor, de la que más comisión deja (tier 1) a
     * la que menos (tier 5). Mientras más baja la comisión, menos descuento se le
     * puede dar al comprador.
     */
    private const CATEGORIES = [
        ['code' => 'Vo', 'name' => 'Verde oscuro', 'tier' => 1],
        ['code' => 'Vc', 'name' => 'Verde claro', 'tier' => 2],
        ['code' => 'A', 'name' => 'Amarillo', 'tier' => 3],
        ['code' => 'N', 'name' => 'Naranja', 'tier' => 4],
        ['code' => 'R', 'name' => 'Rojo', 'tier' => 5],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::CATEGORIES as $category) {
            // `code` también va en los valores: la collation de MySQL compara sin
            // distinguir mayúsculas, así que un "VC" existente se corrige a "Vc".
            CommissionCategory::updateOrCreate(['code' => $category['code']], $category);
        }
    }
}
