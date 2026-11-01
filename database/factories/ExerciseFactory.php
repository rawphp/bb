<?php

namespace Database\Factories;

use App\Domain\Model\Equipment;
use App\Domain\Model\ExerciseLoadType;
use App\Domain\Model\MuscleGroup;
use App\Enums\SfrRating;
use App\Models\Exercise;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Exercise> */
class ExerciseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => null,
            'name' => fake()->unique()->words(3, true),
            'muscle_group' => fake()->randomElement(MuscleGroup::cases()),
            'equipment' => fake()->randomElement(Equipment::cases()),
            'load_type' => fake()->randomElement(ExerciseLoadType::cases()),
            'sfr_rating' => fake()->randomElement(SfrRating::cases()),
            'rep_range_min' => 10,
            'rep_range_max' => 20,
            'is_compound' => false,
            'video_url' => null,
        ];
    }

    /** A custom exercise owned by the user. */
    public function custom(?User $user = null): static
    {
        return $this->state(fn () => ['user_id' => $user->id ?? User::factory()]);
    }
}
