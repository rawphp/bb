<?php

namespace App\Capabilities\Support;

use App\Domain\Model\BodyFatBucket;
use App\Domain\Model\ConsumedTotals;
use App\Domain\Model\DietFilter;
use App\Domain\Model\Equipment;
use App\Domain\Model\ExerciseLoadType;
use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\HeightUnit;
use App\Domain\Model\JointPain;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Priority;
use App\Domain\Model\Pump;
use App\Domain\Model\Sex;
use App\Domain\Model\Soreness;
use App\Domain\Model\VolumeFeedback;
use App\Domain\Model\WeightUnit;
use App\Domain\Safety\RecheckOutcomeKind;
use App\Domain\Safety\ScreeningItem;
use App\Domain\Tokens\TokenLifetime;
use App\Domain\Tokens\TokenScope;

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

    public const HEIGHT_UNITS = [HeightUnit::Cm->value, HeightUnit::FeetInches->value];

    public const CONSUMED_TOTALS = [ConsumedTotals::Planned->value, ConsumedTotals::CheckedIn->value];

    public const BODY_FAT_BUCKETS = [BodyFatBucket::Lowest->value, BodyFatBucket::Low->value, BodyFatBucket::High->value, BodyFatBucket::Highest->value];

    public const EXPERIENCE_LEVELS = [ExperienceLevel::Beginner->value, ExperienceLevel::Intermediate->value, ExperienceLevel::Advanced->value];

    public const DIET_FILTERS = [DietFilter::DairyFree->value, DietFilter::GlutenFree->value, DietFilter::GrainFree->value, DietFilter::LowFodmap->value, DietFilter::Vegetarian->value, DietFilter::Paleo->value, DietFilter::Vegan->value];

    public const SCREENING_ITEMS = [ScreeningItem::AgeOutOfRange->value, ScreeningItem::HeightOutOfRange->value, ScreeningItem::WeightOverLimit->value, ScreeningItem::BmiUnderLimit->value, ScreeningItem::PregnantOrBreastfeeding->value, ScreeningItem::TryingToConceive->value, ScreeningItem::TransgenderOnHormoneTherapy->value, ScreeningItem::HeartThyroidOrPCOS->value, ScreeningItem::WeightLossSurgery->value, ScreeningItem::GallbladderDisease->value, ScreeningItem::AutoimmuneDisease->value, ScreeningItem::Diabetes->value, ScreeningItem::EatingDisorderHistory->value];

    public const RECHECK_OUTCOME_KINDS = [RecheckOutcomeKind::RunNutritionSetup->value, RecheckOutcomeKind::SwitchToTrainingOnly->value, RecheckOutcomeKind::LeaveFatLoss->value, RecheckOutcomeKind::Keep->value];

    public const TOKEN_SCOPES = [TokenScope::Everything->value, TokenScope::ReadOnly->value, TokenScope::HealthBridge->value, TokenScope::Custom->value];

    public const TOKEN_LIFETIMES = [TokenLifetime::Month->value, TokenLifetime::Quarter->value, TokenLifetime::Year->value];
}
