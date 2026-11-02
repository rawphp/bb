<?php

namespace Database\Factories;

use App\Enums\MeasurementSource;
use App\Models\User;
use App\Models\WeighIn;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WeighIn> */
class WeighInFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'weight_kg' => fake()->randomFloat(2, 50, 120),
            'waist_cm' => null,
            'weighed_at' => fake()->dateTimeBetween('-30 days'),
            'source' => MeasurementSource::Manual,
        ];
    }

    /** Posted by the Shortcuts bridge (WT-003). */
    public function shortcut(): static
    {
        return $this->state(fn () => ['source' => MeasurementSource::Shortcut]);
    }
}
