<?php

namespace App\Capabilities\Progress\Data;

use Rawphp\Capabilities\Support\CapabilityData;

final class ExerciseHistoryShowInput extends CapabilityData
{
    public function __construct(
        public readonly string $exercise_id,
    ) {}
}
