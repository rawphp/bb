<?php

namespace App\Capabilities\Onboarding\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** One mark on setup's step track (ONB-022). A mark that can open goes there with `move: edit` and its `step`. */
final class OnboardingStepMark extends CapabilityData
{
    public function __construct(
        #[Field(minimum: 1, maximum: 14)]
        public readonly int $step,
        #[Field(description: '"Body fat"; "Review" for the review')]
        public readonly string $label,
        #[Field(enum: ['done', 'current', 'todo'])]
        public readonly string $state,
        public readonly bool $can_open,
    ) {}
}
