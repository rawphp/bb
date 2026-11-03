<?php

namespace App\Capabilities\Onboarding\Data;

use App\Capabilities\Support\Status;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** One line of the review, with marks such as "Recommended" and "Selected" (ONB-018). */
final class OnboardingReviewRow extends CapabilityData
{
    /** @param  list<Status>  $badges */
    public function __construct(
        public readonly string $label,
        public readonly string $value,
        #[Field(items: Status::class)]
        public readonly array $badges,
    ) {}
}
