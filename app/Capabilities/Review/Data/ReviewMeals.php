<?php

namespace App\Capabilities\Review\Data;

use App\Capabilities\Support\Status;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** Step 2: meals checked in, "Excellent work" when all were, and the week by day with each meal's mark (REV-006). */
final class ReviewMeals extends CapabilityData
{
    /**
     * @param  list<ReviewMealDay>  $days
     * @param  list<ReviewUncheckedMeal>  $unchecked
     */
    public function __construct(
        public readonly Status $message,
        #[Field(items: ReviewMealDay::class)]
        public readonly array $days,
        #[Field(description: 'Deprecated, to be removed in the release after next: the meals of `days` not checked in. Read `days` instead.', items: ReviewUncheckedMeal::class)]
        public readonly array $unchecked,
        #[Field(description: 'Deprecated, to be removed in the release after next: "Check in now". Each meal of `days` has its own label.')]
        public readonly string $check_in_title,
    ) {}
}
