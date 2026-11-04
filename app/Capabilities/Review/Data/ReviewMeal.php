<?php

namespace App\Capabilities\Review\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A planned meal of the reviewed week (REV-006); one not checked in is checked in now with meal_check_in. */
final class ReviewMeal extends CapabilityData
{
    public function __construct(
        public readonly string $meal_id,
        public readonly string $name,
        public readonly bool $is_checked,
        #[Field(description: 'MEAL-047: the check-in against the meal\'s target, drawn as Today\'s box draws it.', enum: ['not_checked_in', 'on_track', 'under', 'over'])]
        public readonly string $check_in,
        #[Field(description: '"Check in Lunch, Sat, 24 Oct", or "Lunch, Sat, 24 Oct: checked in, over target".')]
        public readonly string $label,
    ) {}
}
