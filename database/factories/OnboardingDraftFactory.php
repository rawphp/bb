<?php

namespace Database\Factories;

use App\Models\OnboardingDraft;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OnboardingDraft> */
class OnboardingDraftFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'step' => 2,
            'goal' => 'maintenance',
            'finished_diet_recently' => false,
        ];
    }
}
