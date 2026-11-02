<?php

namespace Database\Factories;

use App\Models\Food;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** A generic library food, per 100 g. @extends Factory<Food> */
class FoodFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => null,
            'name' => fake()->unique()->words(2, true),
            'brand' => null,
            'barcode' => null,
            'source' => 'usda',
            'source_id' => (string) fake()->unique()->numberBetween(100000, 9999999),
            'kind' => 'generic',
            'curated' => false,
            'curated_group' => null,
            'kcal' => 165.0,
            'protein' => 31.0,
            'fat' => 3.6,
            'carbs' => 0.0,
            'fiber' => 0.0,
            'cooked_ratio' => null,
            ...array_fill_keys(Food::DIETS, false),
            'custom_serving' => null,
        ];
    }

    /** A custom food owned by the user (FOOD-060). */
    public function custom(?User $user = null): static
    {
        return $this->state(fn () => [
            'user_id' => $user->id ?? User::factory(),
            'source' => 'custom',
            'source_id' => fake()->uuid(),
            'kind' => 'custom',
            'custom_serving' => ['size' => 1, 'unit' => 'piece', 'grams' => 50, 'protein' => 6, 'fat' => 5, 'carbs' => 0.5, 'fiber' => 0],
        ]);
    }
}
