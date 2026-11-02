<?php

namespace Database\Factories;

use App\Models\DayTarget;
use App\Models\DayTargetMeal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A meal row on a day target with no other meals, added to that day's `meals` too, so the
 * index and the JSON agree as DayTarget would keep them.
 *
 * @extends Factory<DayTargetMeal>
 */
class DayTargetMealFactory extends Factory
{
    public function definition(): array
    {
        return [
            'day_target_id' => DayTarget::factory()->state(['meals' => []]),
            'user_id' => fn (array $attributes) => DayTarget::findOrFail($attributes['day_target_id'])->user_id,
            'position' => 0,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (DayTargetMeal $meal) {
            $day = $meal->dayTarget()->firstOrFail();
            $day->meals = [...$day->meals, ['id' => $meal->id, 'name' => 'Meal', 'kcal' => 500, 'protein_g' => 35, 'fat_g' => 15, 'carbs_g' => 55]];
            $day->saveQuietly();
        });
    }
}
