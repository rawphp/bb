<?php

namespace App\Capabilities\Nutrition\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** MEAL-010: the pre- or post-workout tag. */
final class PeriWorkout extends CapabilityData
{
    public function __construct(
        #[Field(enum: ['pre', 'post'])]
        public readonly string $kind,
        public readonly string $title,
        #[Field(description: 'Workout 5:30 PM')]
        public readonly string $detail,
        public readonly string $help,
    ) {}
}
