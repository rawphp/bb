<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WorkoutSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WorkoutSession> */
class WorkoutSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'mesocycle_id' => null,
            'week' => 1,
            'day' => 1,
            'planned_start_at' => '2026-10-12 09:00:00',
            'planned_end_at' => '2026-10-12 10:00:00',
            'actual_start_at' => null,
            'actual_end_at' => null,
            'is_skipped' => false,
            'notes' => null,
        ];
    }

    public function started(string $at = '2026-10-12 09:05:00'): static
    {
        return $this->state(fn () => ['actual_start_at' => $at]);
    }
}
