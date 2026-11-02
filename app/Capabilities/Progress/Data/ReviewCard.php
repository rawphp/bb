<?php

namespace App\Capabilities\Progress\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** "Weekly review available" (ANA-004, REV-001). */
final class ReviewCard extends CapabilityData
{
    public function __construct(
        public readonly string $title,
        public readonly string $action_title,
    ) {}
}
