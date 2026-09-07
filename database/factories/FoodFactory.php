<?php

namespace Database\Factories;

use App\Models\Food;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Food>
 */
class FoodFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source' => 'admin',
            'status' => 'approved',
            'name_en' => fake()->unique()->words(2, true),
            'name_ar' => null,
            'calories_per_100g' => fake()->randomFloat(1, 20, 600),
            'protein_g_per_100g' => fake()->randomFloat(1, 0, 40),
            'carbs_g_per_100g' => fake()->randomFloat(1, 0, 80),
            'fat_g_per_100g' => fake()->randomFloat(1, 0, 40),
            'fiber_g_per_100g' => fake()->randomFloat(1, 0, 15),
        ];
    }
}
