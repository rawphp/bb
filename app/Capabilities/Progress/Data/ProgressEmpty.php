<?php

namespace App\Capabilities\Progress\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** Shown instead of the chart until there are 4 weigh-ins (WT-012, ONB-032). */
final class ProgressEmpty extends CapabilityData
{
    public function __construct(
        public readonly string $message,
        public readonly string $action_title,
    ) {}
}
