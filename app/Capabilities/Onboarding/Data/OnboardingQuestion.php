<?php

namespace App\Capabilities\Onboarding\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** A yes or no question. `answer` is null until it is answered. */
final class OnboardingQuestion extends CapabilityData
{
    public function __construct(
        public readonly string $question,
        public readonly string $detail,
        public readonly ?bool $answer,
    ) {}
}
