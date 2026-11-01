<?php

namespace App\Capabilities\Onboarding\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Where setup is (ONB-002, UI-007). `number` is the spec's step, 1 to 13, or
 * 14 for the review. `position` of `total` counts only the steps shown, so
 * training-only setup skips the nutrition steps (ONB-005).
 */
final class OnboardingStep extends CapabilityData
{
    public function __construct(
        #[Field(minimum: 1, maximum: 14)]
        public readonly int $number,
        #[Field(enum: ['goal', 'about_you', 'screening', 'glp1', 'body_fat', 'rate_length', 'experience', 'training_days', 'training_plan', 'steps', 'meals', 'weigh_ins', 'calories', 'review'])]
        public readonly string $key,
        public readonly string $title,
        public readonly string $counter,
        public readonly int $position,
        public readonly int $total,
        public readonly string $next_title,
        public readonly bool $can_continue,
        public readonly ?string $continue_hint,
        public readonly bool $can_go_back,
    ) {}
}
