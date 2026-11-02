<?php

namespace App\Capabilities\Exercises\Data;

use App\Capabilities\Support\DomainValues;
use App\Capabilities\Support\ExplicitNulls;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Set an exercise's load step in the user's unit (EX-012, CAP-015), for
 * example a 4.5 kg cable stack. A null step goes back to the TBL-040 default.
 * The smallest step is 0.25, the smallest fractional plate in either unit, so
 * a rep table or load increase never steps by zero or a negative weight.
 */
final class ExerciseLoadStepSetInput extends CapabilityData
{
    use ExplicitNulls;

    public function __construct(
        public readonly string $exercise_id,
        #[Field(minimum: 0.25)]
        public readonly ?float $step,
        #[Field(enum: DomainValues::WEIGHT_UNITS)]
        public readonly string $unit,
    ) {}
}
