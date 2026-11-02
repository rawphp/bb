<?php

namespace App\Capabilities\Onboarding\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/** A callout, badge or pill: text and a tone the server picked (CAP-018). */
final class OnboardingStatus extends CapabilityData
{
    public function __construct(
        public readonly string $text,
        #[Field(enum: ['good', 'neutral', 'warning', 'info'])]
        public readonly string $tone,
    ) {}
}
