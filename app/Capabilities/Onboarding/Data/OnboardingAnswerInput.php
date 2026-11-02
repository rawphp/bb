<?php

namespace App\Capabilities\Onboarding\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Saves answers, then moves (ONB-002). `stay` only saves, as the agent does a
 * few answers at a time (AGT-031). `next` and `back` walk the steps the
 * server shows, and `edit` jumps from the review to `edit_step` (ONB-018).
 */
final class OnboardingAnswerInput extends CapabilityData
{
    public function __construct(
        public readonly OnboardingAnswers $answers = new OnboardingAnswers,
        #[Field(enum: ['stay', 'next', 'back', 'edit'])]
        public readonly string $move = 'stay',
        #[Field(minimum: 1, maximum: 13)]
        public readonly ?int $edit_step = null,
    ) {}
}
