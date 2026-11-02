<?php

namespace Database\Factories;

use App\Domain\Model\PhaseType;
use App\Models\Phase;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Phase> */
class PhaseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => PhaseType::FatLoss,
            'start_date' => '2026-10-12',
            'end_date' => '2026-12-21',
            'target_rate_percent_per_week' => -0.75,
            'event_date' => null,
        ];
    }
}
