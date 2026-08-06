<?php

namespace Database\Seeders;

use App\Models\Category;
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

        foreach ($groups as $group) {
            foreach ($group['colors'] as $color) {
                $code = $codeGenerator->generate($product, $color, $group['size']);

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
                    ],
                );
            }
        }
    }
}
