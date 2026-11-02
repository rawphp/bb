<?php

namespace App\Domain\Progression;

use App\Domain\Defaults\TBL040LoadSteps\LoadType;
use App\Domain\Model\ExperienceLevel;

/** Target weight and reps (PROG-020 to PROG-027, MESO-061, MESO-063). */
final class LoadProgression
{
    /** PROG-021: weekly rise. */
    public const WEEKLY_RISE = 0.02;

    /** PROG-021: weekly rise during Fat loss. */
    public const FAT_LOSS_WEEKLY_RISE = 0.01;

    /** MESO-061: start of a copied mesocycle above the old week 1. */
    public const COPIED_RISE = 0.05;

    /** MESO-061: the same for advanced lifters. */
    public const COPIED_RISE_ADVANCED = 0.025;

    /** PROG-025: further than this from last week's weight shows RIR only. */
    public const FAR_WEIGHT_CHANGE = 0.15;

    /** PROG-026: reps lost per set in week 1. */
    public const WEEK_ONE_DROP = 2;

    private const TOLERANCE = 1e-9;

    /**
     * PROG-020 to PROG-022, PROG-026, PROG-027: targets for this week from last week's sets.
     *
     * @param  list<SetLoad>  $lastWeek
     * @return list<SetTarget>
     */
    public static function targets(array $lastWeek, int $rir, bool $isFatLoss, LoadRules $rules): array
    {
        if ($lastWeek === []) {
            return [];
        }
        $first = self::addedLoad($lastWeek[0], $rules);
        $start = self::raised($first, $isFatLoss ? self::FAT_LOSS_WEEKLY_RISE : self::WEEKLY_RISE, $isFatLoss, $rules);
        $weight = $start->weight;
        $reps = $start->reps;

        // PROG-027: past the top of the range, one step up and back to the middle.
        if ($reps > (int) $rules->repRange->upper) {
            $step = $rules->step($isFatLoss);
            if ($step !== null && $first->weight !== null) {
                $weight = max($weight ?? $first->weight, $first->weight + $step);
                $reps = intdiv((int) $rules->repRange->lower + (int) $rules->repRange->upper, 2);
            } else {
                $reps = (int) $rules->repRange->upper;
            }
        }

        $targets = [];
        foreach ($lastWeek as $index => $set) {
            if ($index > 0) {
                $drop = max(1, $lastWeek[$index - 1]->reps - $set->reps);
                $reps = max(1, $reps - $drop);
            }
            $targets[] = new SetTarget($weight, $reps, $rir);
        }

        return $targets;
    }

    /**
     * PROG-026: week 1 has no last week, so each set drops 2 reps.
     *
     * @return list<SetTarget>
     */
    public static function firstWeekTargets(?float $weight, int $firstSetReps, int $sets, int $rir): array
    {
        $targets = [];
        for ($index = 0; $index < $sets; $index++) {
            $targets[] = new SetTarget($weight, max(1, $firstSetReps - self::WEEK_ONE_DROP * $index), $rir);
        }

        return $targets;
    }

    /** PROG-024, PROG-025: reps for an equal effort at the weight the lifter chose. */
    public static function retarget(SetTarget $target, float $enteredWeight, ?float $lastWeekWeight, LoadRules $rules): Retarget
    {
        $base = $rules->baseLoad();
        $entered = $base + $enteredWeight;
        if ($lastWeekWeight !== null) {
            $last = $base + $lastWeekWeight;
            if ($last > 0 && abs($entered - $last) / $last > self::FAR_WEIGHT_CHANGE + self::TOLERANCE) {
                return Retarget::rirOnly($target->rir);
            }
        }
        $planned = $base + ($target->weight ?? 0.0);
        $e1RM = $planned * (1 + ($target->reps + $target->rir) / 30);
        if ($entered <= 0) {
            return Retarget::reps(0);
        }
        // The epsilon keeps an exact result like 24.0 from falling to 23 on float noise.
        $reps = floor(30 * ($e1RM / $entered - 1) - $target->rir + self::TOLERANCE);

        return Retarget::reps(max(0, (int) $reps));
    }

    /** MESO-061, MESO-063: first set of a copied mesocycle from the old week 1. */
    public static function copiedStart(SetLoad $oldWeekOne, ExperienceLevel $experience, bool $isFatLoss, LoadRules $rules): SetLoad
    {
        $rise = $experience === ExperienceLevel::Advanced ? self::COPIED_RISE_ADVANCED : self::COPIED_RISE;

        return self::raised($oldWeekOne, $rise, $isFatLoss, $rules);
    }

    /** A bodyweight-plus-load set logged without added load carried 0 on top of bodyweight. */
    private static function addedLoad(SetLoad $set, LoadRules $rules): SetLoad
    {
        if ($rules->loadType !== LoadType::BodyweightPlusLoad || $set->weight !== null) {
            return $set;
        }

        return new SetLoad(0.0, $set->reps);
    }

    /**
     * Raises the load by $rise and rounds to the step. When the weight cannot move (no step, no weight, or rounding
     * leaves it), the weight stays and reps go up one.
     */
    private static function raised(SetLoad $logged, float $rise, bool $isFatLoss, LoadRules $rules): SetLoad
    {
        $set = self::addedLoad($logged, $rules);
        $step = $rules->step($isFatLoss);
        if ($step === null || $set->weight === null) {
            return new SetLoad($set->weight, $set->reps + 1);
        }
        $base = $rules->baseLoad();
        $rounded = round((($base + $set->weight) * (1 + $rise) - $base) / $step) * $step;
        if ($rounded > $set->weight + self::TOLERANCE) {
            return new SetLoad($rounded, $set->reps);
        }

        return new SetLoad($set->weight, $set->reps + 1);
    }
}
