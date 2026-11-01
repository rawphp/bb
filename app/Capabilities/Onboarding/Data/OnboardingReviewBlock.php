<?php

namespace App\Capabilities\Onboarding\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * One section of the review (ONB-018). Its Edit link answers with
 * `edit_step`; there is no link when `can_edit` is false (a skipped step).
 */
final class OnboardingReviewBlock extends CapabilityData
{
    /** @param  list<OnboardingReviewRow>  $rows */
    public function __construct(
        public readonly string $title,
        public readonly bool $can_edit,
        public readonly int $edit_step,
        #[Field(items: OnboardingReviewRow::class)]
        public readonly array $rows,
    ) {}
}
