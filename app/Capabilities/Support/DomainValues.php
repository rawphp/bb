<?php

namespace App\Capabilities\Support;

use App\Domain\Model\Equipment;
use App\Domain\Model\ExerciseLoadType;
use App\Domain\Model\JointPain;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Priority;
use App\Domain\Model\Pump;
use App\Domain\Model\Sex;
use App\Domain\Model\Soreness;
use App\Domain\Model\VolumeFeedback;
use App\Domain\Model\WeightUnit;

/**
 * Every value of a domain enum, for a contract's `#[Field(enum: ...)]`. An
 * attribute takes only a constant expression, so it cannot call `cases()`;
 * each list is written once here, and DomainValuesTest holds it to the enum.
 * A contract value the API reads with `Enum::from()` comes from one of these.
 */
final class DomainValues
{
    public const MUSCLE_GROUPS = [MuscleGroup::Chest->value, MuscleGroup::Back->value, MuscleGroup::Quads->value, MuscleGroup::Hamstrings->value, MuscleGroup::Glutes->value, MuscleGroup::SideDelts->value, MuscleGroup::RearDelts->value, MuscleGroup::FrontDelts->value, MuscleGroup::Biceps->value, MuscleGroup::Triceps->value, MuscleGroup::Calves->value, MuscleGroup::Abs->value, MuscleGroup::Traps->value, MuscleGroup::Forearms->value];

    public const PHASE_TYPES = [PhaseType::FatLoss->value, PhaseType::MuscleGain->value, PhaseType::Maintenance->value, PhaseType::MiniCut->value];

    public const EQUIPMENT = [Equipment::Barbell->value, Equipment::EzBar->value, Equipment::TrapBar->value, Equipment::Dumbbell->value, Equipment::Kettlebell->value, Equipment::Machine->value, Equipment::Cable->value, Equipment::SmithMachine->value, Equipment::Bodyweight->value];

    public const LOAD_TYPES = [ExerciseLoadType::Barbell->value, ExerciseLoadType::Dumbbell->value, ExerciseLoadType::Machine->value, ExerciseLoadType::Cable->value, ExerciseLoadType::Bodyweight->value, ExerciseLoadType::BodyweightPlusLoad->value, ExerciseLoadType::SmithMachine->value];

    public const WEIGHT_UNITS = [WeightUnit::Kg->value, WeightUnit::Lb->value];

    public const PRIORITIES = [Priority::Emphasize->value, Priority::Grow->value, Priority::Maintain->value];

    public const SEXES = [Sex::Female->value, Sex::Male->value];

    public const PUMPS = [Pump::Low->value, Pump::Moderate->value, Pump::Amazing->value];

    public const VOLUME_FEEDBACK = [VolumeFeedback::NotEnough->value, VolumeFeedback::JustRight->value, VolumeFeedback::PushedMyLimits->value, VolumeFeedback::TooMuch->value];

    public const SORENESS = [Soreness::NeverGotSore->value, Soreness::HealedAWhileAgo->value, Soreness::HealedJustOnTime->value, Soreness::StillSore->value];

    public const JOINT_PAIN = [JointPain::None->value, JointPain::Low->value, JointPain::Moderate->value, JointPain::ALot->value];

    public const TONES = [Tone::Good->value, Tone::Neutral->value, Tone::Warning->value, Tone::Info->value];
}
