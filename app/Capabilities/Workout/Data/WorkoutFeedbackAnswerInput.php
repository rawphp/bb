<?php

namespace App\Capabilities\Workout\Data;

use App\Capabilities\Support\AcceptsNullEnums;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\Pump;
use App\Domain\Model\Soreness;
use App\Domain\Model\VolumeFeedback;
use Rawphp\Capabilities\Attributes\Field;
use Rawphp\Capabilities\Support\CapabilityData;

/**
 * Save one muscle's Feedback sheet (FB-001 to FB-005, FB-009), answering it or
 * changing it until the muscle trains again. `soreness` is null or left out when
 * the sheet does not ask it (FB-007). The values are the sheet's choice values.
 */
final class WorkoutFeedbackAnswerInput extends CapabilityData
{
    use AcceptsNullEnums;

    /**
     * @param  list<WorkoutJointPainAnswer>  $joint_pain
     */
    public function __construct(
        public readonly string $workout_id,
        #[Field(enum: [MuscleGroup::Chest->value, MuscleGroup::Back->value, MuscleGroup::Quads->value, MuscleGroup::Hamstrings->value, MuscleGroup::Glutes->value, MuscleGroup::SideDelts->value, MuscleGroup::RearDelts->value, MuscleGroup::FrontDelts->value, MuscleGroup::Biceps->value, MuscleGroup::Triceps->value, MuscleGroup::Calves->value, MuscleGroup::Abs->value, MuscleGroup::Traps->value, MuscleGroup::Forearms->value])]
        public readonly string $muscle_group,
        #[Field(items: WorkoutJointPainAnswer::class)]
        public readonly array $joint_pain,
        #[Field(enum: [Pump::Low->value, Pump::Moderate->value, Pump::Amazing->value])]
        public readonly string $pump,
        #[Field(enum: [VolumeFeedback::NotEnough->value, VolumeFeedback::JustRight->value, VolumeFeedback::PushedMyLimits->value, VolumeFeedback::TooMuch->value])]
        public readonly string $volume,
        #[Field(enum: [Soreness::NeverGotSore->value, Soreness::HealedAWhileAgo->value, Soreness::HealedJustOnTime->value, Soreness::StillSore->value])]
        public readonly ?string $soreness = null,
    ) {}

    public static function rules(): array
    {
        return ['workout_id' => ['ulid']];
    }
}
