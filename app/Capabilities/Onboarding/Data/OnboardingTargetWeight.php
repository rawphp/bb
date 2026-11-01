<?php

namespace App\Capabilities\Onboarding\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Step 6's target weight in the unit on screen (CAP-015). `note` says why it
 * cannot be edited, and is "" when it can.
 */
final class OnboardingTargetWeight extends CapabilityData
{
    public function __construct(
        public readonly ?float $value,
        public readonly string $unit,
        public readonly string $text,
        public readonly bool $can_edit,
        public readonly string $note,
    ) {}
}
