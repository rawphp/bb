<?php

namespace App\Capabilities\Workout\Data;

use App\Capabilities\Support\DomainValues;
use App\Capabilities\Support\ExplicitNulls;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Save one muscle's Feedback sheet (FB-001 to FB-005, FB-009), answering it or
 * changing it until the muscle trains again. `soreness` is null or left out when
 * the sheet does not ask it (FB-007). The values are the sheet's choice values.
 */
final class WorkoutFeedbackAnswerInput extends CapabilityData
{
    use ExplicitNulls;

    /**
     * @param  list<WorkoutJointPainAnswer>  $joint_pain
     */
    public function __construct(
        public readonly string $workout_id,
        #[Field(enum: DomainValues::MUSCLE_GROUPS)]
        public readonly string $muscle_group,
        #[Field(items: WorkoutJointPainAnswer::class)]
        public readonly array $joint_pain,
        #[Field(enum: DomainValues::PUMPS)]
        public readonly string $pump,
        #[Field(enum: DomainValues::VOLUME_FEEDBACK)]
        public readonly string $volume,
        #[Field(enum: DomainValues::SORENESS)]
        public readonly ?string $soreness = null,
    ) {}

    public static function rules(): array
    {
        return ['workout_id' => ['ulid']];
    }
}
