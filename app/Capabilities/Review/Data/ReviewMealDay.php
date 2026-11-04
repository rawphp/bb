<?php

namespace App\Capabilities\Review\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A day of the reviewed week and its planned meals, in order (REV-006). */
final class ReviewMealDay extends CapabilityData
{
    /** @param  list<ReviewMeal>  $meals */
    public function __construct(
        public readonly string $date,
        #[Field(description: '"Sat".')]
        public readonly string $weekday,
        #[Field(description: '"24".')]
        public readonly string $day_number,
        #[Field(items: ReviewMeal::class)]
        public readonly array $meals,
    ) {}
}
