<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\CommissionCategory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\UnitType;
use App\Services\VariantCodeGenerator;
use Illuminate\Database\Seeder;

class ProductVariantSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(VariantCodeGenerator $codeGenerator): void
    {
        $supplier = Supplier::where('name', 'Interceramic')->firstOrFail();
        $category = Category::where('code_prefix', 'PIS')->firstOrFail();
        $unitType = UnitType::where('name', 'm2')->firstOrFail();
        $commissionCategoryIds = CommissionCategory::pluck('id', 'code');

        $product = Product::updateOrCreate(
            ['supplier_id' => $supplier->id, 'name' => 'Creato'],
            [
                'category_id' => $category->id,
                'unit_type_id' => $unitType->id,
                'purchase_unit' => 'caja',
            ],
        );

        $groups = [
            [
                'size' => '60X120',
                'colors' => ['Taupe', 'Terracota', 'Ivory', 'Gray', 'Graphite'],
                'price_per_m2' => 359.00,
                'price_per_box' => 516.96,
                'pieces_per_box' => 2,
                'm2_per_box' => 1.440,
            ],
            [
                'size' => '30X60',
                'colors' => ['Taupe', 'Terracota', 'Ivory', 'Gray', 'Graphite', 'Teal', 'Espresso'],
                'price_per_m2' => 199.00,
                'price_per_box' => 322.38,
                'pieces_per_box' => 9,
                'm2_per_box' => 1.620,
            ],
            [
                'size' => '20X20',
                'colors' => ['Teal', 'Terracota', 'Taupe', 'Gray Canvas'],
                'price_per_m2' => 199.00,
                'price_per_box' => 199.00,
                'pieces_per_box' => 25,
                'm2_per_box' => 1.000,
            ],
            [
                'size' => '20X20',
                'colors' => ['Teal', 'Espresso', 'Taupe Blend'],
                'price_per_m2' => 229.00,
                'price_per_box' => 229.00,
                'pieces_per_box' => 25,
                'm2_per_box' => 1.000,
            ],
        ];

        // Atributos técnicos por tamaño+color (no por "grupo" de precio): el 20X20
        // se define en dos grupos de precio distintos que comparten el color Teal,
        // así que se resuelven aquí para que ese color quede consistente sin
        // importar en qué grupo se procese.
        $tileAttributes = [
            '60X120' => [
                'Taupe' => ['pei' => '4', 'ett' => 3, 'commission_category' => 'Vo'],
                'Terracota' => ['pei' => '4', 'ett' => 3, 'commission_category' => 'Vo'],
                'Ivory' => ['pei' => '4', 'ett' => 3, 'commission_category' => 'Vo'],
                'Gray' => ['pei' => '4', 'ett' => 3, 'commission_category' => 'Vo'],
                'Graphite' => ['pei' => '4', 'ett' => 3, 'commission_category' => 'Vo'],
            ],
            '30X60' => [
                'Taupe' => ['pei' => '3/4', 'ett' => 3, 'commission_category' => 'N'],
                'Terracota' => ['pei' => '3/4', 'ett' => 3, 'commission_category' => 'N'],
                'Ivory' => ['pei' => '3/4', 'ett' => 3, 'commission_category' => 'N'],
                'Gray' => ['pei' => '3/4', 'ett' => 3, 'commission_category' => 'N'],
                'Graphite' => ['pei' => '3/4', 'ett' => 3, 'commission_category' => 'N'],
                'Teal' => ['pei' => '3/4', 'ett' => 3, 'commission_category' => 'N'],
                'Espresso' => ['pei' => '3/4', 'ett' => 3, 'commission_category' => 'N'],
            ],
            '20X20' => [
                'Teal' => ['pei' => '3/4', 'ett' => 3, 'commission_category' => 'N'],
                'Terracota' => ['pei' => '3/4', 'ett' => 3, 'commission_category' => 'N'],
                'Taupe' => ['pei' => '3/4', 'ett' => 3, 'commission_category' => 'N'],
                'Gray Canvas' => ['pei' => '3/4', 'ett' => 3, 'commission_category' => 'N'],
                'Espresso' => ['pei' => '3/4', 'ett' => 3, 'commission_category' => 'A'],
                'Taupe Blend' => ['pei' => '3/4', 'ett' => 3, 'commission_category' => 'A'],
            ],
        ];

        // El "Teal" 20X20 del grupo de 229 colisiona en abreviatura con el "Teal"
        // 20X20 del grupo de 199, así que VariantCodeGenerator le asigna el código
        // largo (TEAL en vez de TEA). Esa fila puntual va en categoría A junto con
        // Espresso y Taupe Blend, no en N como el resto de la tabla por color.
        $codeOverrides = [
            'PIS-CREATO-TEAL-20X20' => ['pei' => '3/4', 'ett' => 3, 'commission_category' => 'A'],
        ];

        foreach ($groups as $group) {
            foreach ($group['colors'] as $color) {
                $code = $codeGenerator->generate($product, $color, $group['size']);
                $attributes = $codeOverrides[$code] ?? $tileAttributes[$group['size']][$color] ?? null;

                ProductVariant::updateOrCreate(
                    ['code' => $code],
                    [
                        'product_id' => $product->id,
                        'color' => $color,
                        'size' => $group['size'],
                        'price_per_m2' => $group['price_per_m2'],
                        'price_per_box' => $group['price_per_box'],
                        'pieces_per_box' => $group['pieces_per_box'],
                        'm2_per_box' => $group['m2_per_box'],
                        'pei' => $attributes['pei'] ?? null,
                        'ett' => $attributes['ett'] ?? null,
                        'commission_category_id' => $attributes
                            ? $commissionCategoryIds[$attributes['commission_category']] ?? null
                            : null,
                    ],
                );
            }
        }
    }
}
