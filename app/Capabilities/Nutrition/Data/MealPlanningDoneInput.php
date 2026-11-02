<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** meal_planning_done: Done planning foods, or Still planning foods (DAY-004, MEAL-045). */
final class MealPlanningDoneInput extends CapabilityData
{
    public function __construct(
        public readonly string $meal_id,
        public readonly bool $done,
    ) {}
}
