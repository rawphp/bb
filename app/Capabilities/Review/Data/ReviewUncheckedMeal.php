<?php

namespace App\Capabilities\Review\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Deprecated: a meal of the reviewed week not checked in, as review_show sent it before `days` (REV-006). */
final class ReviewUncheckedMeal extends CapabilityData
{
    public function __construct(
        public readonly string $meal_id,
        public readonly string $name,
        #[Field(description: '"Thu, 8 Oct".')]
        public readonly string $date_label,
    ) {}
}
