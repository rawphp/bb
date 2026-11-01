<?php

namespace Database\Factories;

use App\Models\Exercise;
use App\Models\ExerciseNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExerciseNote> */
class ExerciseNoteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'exercise_id' => Exercise::factory(),
            'note' => 'Seat on notch 4.',
        ];
    }
}
