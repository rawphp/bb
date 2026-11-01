<?php

namespace App\Capabilities\Workout\Data;

use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Save one muscle's Feedback sheet (FB-001 to FB-005, FB-009), answering it or
 * changing it until the muscle trains again. `soreness` is left out when the
 * sheet does not ask it (FB-007). The values are the sheet's choice values.
 */
final class WorkoutFeedbackAnswerInput extends CapabilityData
{
    /**
     * @param  list<WorkoutJointPainAnswer>  $joint_pain
     */
    public function __construct(
        public readonly string $workout_id,
        #[Field(enum: ['chest', 'back', 'quads', 'hamstrings', 'glutes', 'sideDelts', 'rearDelts', 'frontDelts', 'biceps', 'triceps', 'calves', 'abs', 'traps', 'forearms'])]
        public readonly string $muscle_group,
        #[Field(items: WorkoutJointPainAnswer::class)]
        public readonly array $joint_pain,
        #[Field(enum: ['low', 'moderate', 'amazing'])]
        public readonly string $pump,
        #[Field(enum: ['not_enough', 'just_right', 'pushed_limits', 'too_much'])]
        public readonly string $volume,
        #[Field(enum: ['never_sore', 'healed_a_while_ago', 'healed_on_time', 'still_sore'])]
        public readonly ?string $soreness = null,
    ) {}

    public static function rules(): array
    {
        return ['workout_id' => ['required', 'ulid']];
    }
}
