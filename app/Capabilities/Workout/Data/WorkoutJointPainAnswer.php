<?php

namespace App\Capabilities\Workout\Data;

use App\Capabilities\Support\DomainValues;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * FB-002: the joint answer for one library exercise of the muscle.
 */
final class WorkoutJointPainAnswer extends CapabilityData
{
    public function __construct(
        public readonly string $exercise_id,
        #[Field(enum: DomainValues::JOINT_PAIN)]
        public readonly string $answer,
    ) {}
}
