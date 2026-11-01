<?php

namespace App\Capabilities\Exercises\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Set an exercise's load step in the user's unit (EX-012, CAP-015), for
 * example a 4.5 kg cable stack. A null step goes back to the TBL-040 default.
 */
final class ExerciseLoadStepSetInput extends CapabilityData
{
    public function __construct(
        public readonly string $exercise_id,
        public readonly ?float $step,
        #[Field(enum: ['kg', 'lb'])]
        public readonly string $unit,
    ) {}
}
