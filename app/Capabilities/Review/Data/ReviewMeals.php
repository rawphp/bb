<?php

namespace App\Capabilities\Review\Data;

use App\Capabilities\Reads\Data\Today\Status;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Step 2: meals checked in, "Excellent work" when all were, and the rest to check in now. */
final class ReviewMeals extends CapabilityData
{
    /** @param  list<ReviewMeal>  $unchecked */
    public function __construct(
        public readonly Status $message,
        #[Field(items: ReviewMeal::class)]
        public readonly array $unchecked,
        public readonly string $check_in_title,
    ) {}
}
