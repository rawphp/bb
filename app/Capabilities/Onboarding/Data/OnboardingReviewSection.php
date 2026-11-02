<?php

namespace App\Capabilities\Onboarding\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * The review (ONB-018 to ONB-020). `note` is "" in training-only mode.
 * `starting_title` and `starting_text` are the loading screen while
 * `onboarding_complete` runs.
 */
final class OnboardingReviewSection extends CapabilityData
{
    /** @param  list<OnboardingReviewBlock>  $sections */
    public function __construct(
        #[Field(items: OnboardingReviewBlock::class)]
        public readonly array $sections,
        public readonly string $note,
        public readonly string $starting_title,
        public readonly string $starting_text,
    ) {}
}
