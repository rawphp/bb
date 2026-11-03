<?php

namespace App\Domain\Plan;

use App\Domain\Macros\TargetRefusalReason;
use App\Domain\Plan\PlanRebuildRefusal\Reason;
use App\Domain\WeekBuilder\WeekBuildRefusal;
use App\Domain\WeekBuilder\WeekBuildRefusalReason;

/** Why a rebuild of the targets was refused (SET-004, PLAN-002, SAFE-011, SAFE-012, SCH-041). */
final readonly class PlanRebuildRefusal
{
    private function __construct(public Reason $reason) {}

    public static function planNotStarted(): self
    {
        return new self(Reason::PlanNotStarted);
    }

    public static function noPhase(): self
    {
        return new self(Reason::NoPhase);
    }

    public static function bmiAtOrBelowFloor(): self
    {
        return new self(Reason::BmiAtOrBelowFloor);
    }

    public static function breaksFloor(): self
    {
        return new self(Reason::BreaksFloor);
    }

    public static function unbalanced(): self
    {
        return new self(Reason::Unbalanced);
    }

    public static function mealsDoNotFit(): self
    {
        return new self(Reason::MealsDoNotFit);
    }

    /** The refusal a week build gave, in the one place that maps it. */
    public static function fromWeekBuild(WeekBuildRefusal $refusal): self
    {
        return match ($refusal->reason) {
            WeekBuildRefusalReason::NoPhase => self::noPhase(),
            WeekBuildRefusalReason::Targets => match ($refusal->targets?->reason) {
                TargetRefusalReason::BmiAtOrBelowFloor => self::bmiAtOrBelowFloor(),
                TargetRefusalReason::BreaksFloor, null => self::breaksFloor(),
            },
            WeekBuildRefusalReason::MealsDoNotFit => self::mealsDoNotFit(),
            WeekBuildRefusalReason::Move => self::unbalanced(),
        };
    }

    /** What the user reads. Every one says the targets were not changed. */
    public function message(): string
    {
        return match ($this->reason) {
            Reason::PlanNotStarted => 'Your plan has not started yet, so there are no targets to update. Your targets were not changed.',
            Reason::NoPhase => 'No phase covers this week, so your targets were not changed.',
            Reason::BmiAtOrBelowFloor => 'Your BMI is too low to plan a cut. Your targets were not changed.',
            Reason::BreaksFloor => 'These details would take your carbs or fat below the safe minimum. Your targets were not changed.',
            Reason::Unbalanced => 'The days could not be balanced. Your targets were not changed.',
            Reason::MealsDoNotFit => 'Your eating window cannot hold your meals. Your targets were not changed.',
        };
    }
}
