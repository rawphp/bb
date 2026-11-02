<?php

namespace App\Capabilities\WeighIns\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/** How to weigh in, and that only the trend matters (WT-005). */
final class WeighInHelp extends CapabilityData
{
    public function __construct(
        public readonly string $title,
        public readonly string $message,
    ) {}
}
