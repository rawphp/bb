<?php

namespace App\Capabilities\Progress\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** Steps against today's target range (ANA-005). */
final class StepsCard extends CapabilityData
{
    public function __construct(
        public readonly string $title,
        public readonly ProgressBar $bar,
        public readonly string $target_label,
    ) {}
}
