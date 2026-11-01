<?php

namespace App\Domain\Safety;

use App\Domain\Model\Meal;
use App\Domain\Model\PhaseType;

final class SafetyWarnings
{
    /** SAFE-005: fastest allowed loss, percent of bodyweight per week. SAFE-013: unchanged on a GLP-1. */
    public const MAX_FAT_LOSS_RATE_PERCENT = 1.0;

    /** SAFE-009: fat never below this share of any day's calories. */
    public const FAT_FLOOR_SHARE_OF_CALORIES = 0.15;

    /** SAFE-010: minimum grams of fat and of carbs in every meal. */
    public const MEAL_MINIMUM_G = 5.0;

    public const KCAL_PER_GRAM_FAT = 9.0;

    /** SAFE-013: consumed under this share of target counts as a low week. */
    public const GLP1_LOW_INTAKE_SHARE = 0.8;

    /** SAFE-013: low weeks running before the warning. */
    public const GLP1_LOW_WEEKS_RUNNING = 2;

    /** SAFE-015: step targets above this warn during fat loss. */
    public const MAX_STEP_TARGET_WITHOUT_WARNING = 12_000;

    /**
     * SAFE-013: on a GLP-1, the latest 2 weeks both under 80% of target.
     *
     * @param  list<IntakeWeek>  $weeks  oldest first
     */
    public static function glp1MuscleLoss(bool $usesGLP1, array $weeks): ?SafetyWarning
    {
        if (! $usesGLP1 || count($weeks) < self::GLP1_LOW_WEEKS_RUNNING) {
            return null;
        }

        foreach (array_slice($weeks, -self::GLP1_LOW_WEEKS_RUNNING) as $week) {
            if ($week->averageConsumedKcal >= $week->averageTargetKcal * self::GLP1_LOW_INTAKE_SHARE) {
                return null;
            }
        }

        return SafetyWarning::glp1MuscleLoss();
    }

    /** SAFE-015: a daily step target above 12,000 in Fat loss or Mini-cut (PLAN-009). */
    public static function stepTarget(int $dailySteps, PhaseType $phase): ?SafetyWarning
    {
        return $phase->isFatLoss() && $dailySteps > self::MAX_STEP_TARGET_WITHOUT_WARNING
            ? SafetyWarning::highStepTarget()
            : null;
    }

    /**
     * SAFE-018: a planned or checked-in day under the fat floor (SAFE-009) or the per-meal minimums (SAFE-010).
     *
     * @param  list<Meal>  $meals
     * @return list<SafetyWarning>
     */
    public static function nutrients(array $meals): array
    {
        $warnings = [];
        $kcal = 0.0;
        $fatG = 0.0;
        foreach ($meals as $meal) {
            $kcal += $meal->target->kcal;
            $fatG += $meal->target->fatG;
        }

        $floorG = $kcal * self::FAT_FLOOR_SHARE_OF_CALORIES / self::KCAL_PER_GRAM_FAT;
        if ($fatG < $floorG) {
            $warnings[] = SafetyWarning::dayFatBelowFloor((int) ceil($floorG));
        }

        foreach ($meals as $meal) {
            if ($meal->target->fatG < self::MEAL_MINIMUM_G) {
                $warnings[] = SafetyWarning::mealFatBelowMinimum($meal->name);
            }
            if ($meal->target->carbsG < self::MEAL_MINIMUM_G) {
                $warnings[] = SafetyWarning::mealCarbsBelowMinimum($meal->name);
            }
        }

        return $warnings;
    }

    /**
     * ONB-021: choosing Fat loss after a diet that ended in the past 8 weeks (the step-1 answer)
     * recommends Maintenance first. The user can override.
     */
    public static function maintenanceFirst(PhaseType $goal, bool $finishedFatLossRecently): ?SafetyWarning
    {
        return $goal->isFatLoss() && $finishedFatLossRecently ? SafetyWarning::maintenanceFirst() : null;
    }
}
