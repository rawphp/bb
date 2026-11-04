<?php

namespace App\Capabilities\Review\Data;

use App\Capabilities\Support\Status;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Step 2: meals checked in, "Excellent work" when all were, and the week by day with each meal's mark (REV-006). */
final class ReviewMeals extends CapabilityData
{
    /** @param  list<ReviewMealDay>  $days */
    public function __construct(
        public readonly Status $message,
        #[Field(items: ReviewMealDay::class)]
        public readonly array $days,
    ) {}
}
