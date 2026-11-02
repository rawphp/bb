<?php

namespace App\Capabilities\Review\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** A meal of the reviewed week that was not checked in; checked in now with meal_check_in. */
final class ReviewMeal extends CapabilityData
{
    public function __construct(
        public readonly string $meal_id,
        public readonly string $name,
        public readonly string $date_label,
    ) {}
}
