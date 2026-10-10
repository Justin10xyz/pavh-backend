<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\SimpleProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SimpleProduct>
 */
class SimpleProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Un producto simple solo puede pertenecer a una categoría tipo 'simple'.
            'category_id' => fn () => Category::create([
                'name' => fake()->unique()->words(2, true),
                'code_prefix' => strtoupper(fake()->unique()->lexify('???')),
                'product_form_type' => 'simple',
            ])->id,
            'name' => fake()->words(3, true),
            'price' => fake()->randomFloat(2, 10, 1000),
            'description' => fake()->optional()->sentence(),
            'stock_quantity' => fake()->numberBetween(0, 100),
        ];
    }
}
