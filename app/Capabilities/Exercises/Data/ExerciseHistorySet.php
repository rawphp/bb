<?php

namespace App\Capabilities\Exercises\Data;

use App\Capabilities\Train\Data\Pill;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * One logged set on the History tab (EX-002): "82.5 kg × 9", with a
 * "Best e1RM" pill on the day's best set.
 */
final class ExerciseHistorySet extends CapabilityData
{
    public function __construct(
        public readonly string $id,
        public readonly string $text,
        public readonly ?Pill $badge,
    ) {}
}
