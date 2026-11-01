<?php

namespace Database\Factories;

use App\Enums\MeasurementSource;
use App\Models\Step;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Step> */
class StepFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'date' => '2026-10-12',
            'count' => 8500,
            'source' => MeasurementSource::Manual,
        ];
    }

    /** Posted by the Shortcuts bridge (INT-001). */
    public function shortcut(): static
    {
        return $this->state(fn () => ['source' => MeasurementSource::Shortcut]);
    }
}
