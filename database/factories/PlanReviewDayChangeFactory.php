<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\PlanReviewDayChange;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PlanReviewDayChange> */
class PlanReviewDayChangeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'effective_on' => '2026-10-19',
            'review_weekday' => 4,
        ];
    }
}
