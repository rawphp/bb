<?php

namespace App\Capabilities\Exercises\Data;

use App\Capabilities\Support\DomainValues;
use App\Capabilities\Train\Data\Measure;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * The load step field on an exercise's Detail tab (EX-012, TBL-040). `step` is
 * the user's own step, null when they use the default; `default_text` is the
 * TBL-040 default after micro plates ("2.5 kg"); `unit` labels the field.
 */
final class ExerciseLoadStep extends CapabilityData
{
    public function __construct(
        public readonly ?Measure $step,
        public readonly string $default_text,
        #[Field(enum: DomainValues::WEIGHT_UNITS)]
        public readonly string $unit,
    ) {}
}
