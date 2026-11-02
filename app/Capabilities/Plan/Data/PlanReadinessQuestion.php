<?php

namespace App\Capabilities\Plan\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * A PH-046 question before a new Fat loss or Muscle gain ("Is diet fatigue
 * low?"). The screen shows `warning` when the user answers no. The answers
 * only warn, so no write sends them.
 */
final class PlanReadinessQuestion extends CapabilityData
{
    public function __construct(
        public readonly string $text,
        public readonly string $warning,
    ) {}
}
