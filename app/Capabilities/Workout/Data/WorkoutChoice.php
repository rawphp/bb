<?php

namespace App\Capabilities\Workout\Data;

use Rawphp\Capabilities\Support\CapabilityData;

/**
 * One row of a single-choice list (UI-006): a feedback answer or a set type.
 * `value` is what the write sends. `note` is the line shown with the row or
 * once it is picked: the myorep guidance (WO-021), or the SAFE-016 note on
 * "A lot of pain".
 */
final class WorkoutChoice extends CapabilityData
{
    public function __construct(
        public readonly string $value,
        public readonly string $label,
        public readonly ?string $note,
    ) {}
}
