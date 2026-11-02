<?php

namespace App\Domain\Phases;

use App\Domain\Defaults\TBL001BodyFatBuckets;
use App\Domain\Defaults\TBL010Phases;
use App\Domain\Defaults\TBL012TrainingDefaults;
use App\Domain\Defaults\TBL012TrainingDefaults\Column;
use App\Domain\Defaults\TBL012TrainingDefaults\Landmark;
use App\Domain\Defaults\TBL012TrainingDefaults\LandmarkOffset;
use App\Domain\Defaults\TBL013MuscleGainBodyFat;
use App\Domain\Model\BodyFatBucket;
use App\Domain\Model\Mesocycle;
use App\Domain\Model\Phase;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Priority;
use App\Domain\Model\Sex;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The non-blocking phase checks. Whole-day maths happens in $timeZone, UTC unless given, so it does not depend on
 * the server's zone.
 */
final class PhaseWarnings
{
    /** PH-010: most muscles that can be emphasized in muscle gain. */
    public const MAX_EMPHASIZED_IN_MUSCLE_GAIN = 2;

    /** PH-045: months without maintenance before one is suggested. */
    public const MONTHS_WITHOUT_MAINTENANCE = 5;

    /** PH-045: length of the suggested phase. */
    public const SUGGESTED_MAINTENANCE_WEEKS = 4;

    /** PH-040: estimated body fat limit, percent. */
    public static function bodyFatLimitPercent(Sex $sex): float
    {
        return $sex === Sex::Male ? 20.0 : 30.0;
    }

    /**
     * PH-040: what the warning recommends.
     *
     * @return list<PhaseType>
     */
    public static function bodyFatRecommendedPhases(): array
    {
        return [PhaseType::MiniCut, PhaseType::FatLoss];
    }

    /**
     * PH-010
     *
     * @param  array<string, Priority>  $priorities
     */
    public static function tooManyEmphasized(PhaseType $phase, array $priorities): ?PhaseWarning
    {
        if ($phase !== PhaseType::MuscleGain) {
            return null;
        }
        $count = count(array_filter($priorities, fn (Priority $priority) => $priority === Priority::Emphasize));

        return $count > self::MAX_EMPHASIZED_IN_MUSCLE_GAIN ? PhaseWarning::tooManyEmphasized() : null;
    }

    /**
     * PH-011 (Mini-cut too, PLAN-009)
     *
     * @param  array<string, Priority>  $priorities
     */
    public static function specialisingDuringCut(PhaseType $phase, array $priorities): ?PhaseWarning
    {
        if (! $phase->isFatLoss()) {
            return null;
        }
        $hasEmphasis = in_array(Priority::Emphasize, $priorities, true);
        $hasLower = in_array(Priority::Grow, $priorities, true) || in_array(Priority::Maintain, $priorities, true);

        return $hasEmphasis && $hasLower ? PhaseWarning::specialisingDuringCut() : null;
    }

    /** PH-012: resensitization, the Maintain preset, or a ceiling below muscle gain's MRV + 2. */
    public static function lowVolumePlan(PhaseType $phase, Mesocycle $mesocycle): ?PhaseWarning
    {
        if ($phase !== PhaseType::MuscleGain) {
            return null;
        }
        $allMaintain = $mesocycle->musclePriorities !== []
            && array_filter($mesocycle->musclePriorities, fn (Priority $priority) => $priority !== Priority::Maintain) === [];
        $lowCeiling = self::ceilingIsBelowMuscleGain(TBL012TrainingDefaults::row($mesocycle->trainingDefaultsColumn())->ceiling);

        return $mesocycle->isResensitization || $allMaintain || $lowCeiling ? PhaseWarning::lowVolumeSurplus() : null;
    }

    private static function ceilingIsBelowMuscleGain(LandmarkOffset $ceiling): bool
    {
        $gain = TBL012TrainingDefaults::row(Column::MuscleGain)->ceiling;
        if ($gain->landmark !== Landmark::Mrv) {
            return false;
        }

        return $ceiling->landmark === Landmark::Mrv ? $ceiling->sets < $gain->sets : true;
    }

    /** PH-038: gain to mini-cut under 2:1. */
    public static function miniCutLength(int $previousMuscleGainWeeks, int $miniCutWeeks): ?PhaseWarning
    {
        if ($miniCutWeeks <= 0) {
            return null;
        }
        $ratio = $previousMuscleGainWeeks / $miniCutWeeks;

        return $ratio < TBL010Phases::MINIMUM_GAIN_TO_MINI_CUT_LENGTH_RATIO ? PhaseWarning::miniCutTooLong() : null;
    }

    /** PH-040: (start fat mass + fraction x weight gained) / current trend weight, percent. */
    public static function estimatedBodyFatPercent(
        Sex $sex, BodyFatBucket $bucket, float $startWeightKg, float $currentTrendWeightKg,
    ): float {
        $startFat = $startWeightKg * TBL001BodyFatBuckets::bodyFatPercent($sex, $bucket) / 100;
        $gained = $currentTrendWeightKg - $startWeightKg;
        $fat = $startFat + TBL013MuscleGainBodyFat::FAT_FRACTION_OF_WEIGHT_GAINED * $gained;

        return $fat / $currentTrendWeightKg * 100;
    }

    /** PH-040: warns above 20% (men) or 30% (women). */
    public static function bodyFatWarning(
        Sex $sex, BodyFatBucket $bucket, float $startWeightKg, float $currentTrendWeightKg,
    ): ?PhaseWarning {
        $percent = self::estimatedBodyFatPercent($sex, $bucket, $startWeightKg, $currentTrendWeightKg);

        return $percent > self::bodyFatLimitPercent($sex) ? PhaseWarning::bodyFatHigh($percent) : null;
    }

    /** PH-034: a fat loss after maintenance shorter than 60% of the previous fat loss. */
    public static function shortMaintenanceWarning(int $maintenanceWeeks, int $previousFatLossWeeks): ?PhaseWarning
    {
        return $maintenanceWeeks * NextPhase::MIN_MAINTENANCE_SHARE_DENOMINATOR
            < $previousFatLossWeeks * NextPhase::MIN_MAINTENANCE_SHARE_NUMERATOR
            ? PhaseWarning::shortMaintenanceBeforeFatLoss() : null;
    }

    /**
     * PH-042: the phase's last day is after its event date. end is exclusive (the day after the last week), so its
     * last day is the day before; Swift compares end itself and so warns for a phase that finishes on the event day.
     * A phase with no end (PH-005) has no end date to check.
     */
    public static function runsPastEvent(Phase $phase, DateTimeZone $timeZone = new DateTimeZone('UTC')): ?PhaseWarning
    {
        if ($phase->eventDate === null || $phase->end === null) {
            return null;
        }

        return self::startOfDay($phase->end, $timeZone)->modify('-1 day') > self::startOfDay($phase->eventDate, $timeZone)
            ? PhaseWarning::runsPastEvent() : null;
    }

    /** PH-043: whole days from $today to the event; null without one or once it has passed. */
    public static function daysToEvent(Phase $phase, DateTimeImmutable $today, DateTimeZone $timeZone = new DateTimeZone('UTC')): ?int
    {
        if ($phase->eventDate === null) {
            return null;
        }
        $diff = self::startOfDay($today, $timeZone)->diff(self::startOfDay($phase->eventDate, $timeZone));
        $days = (int) $diff->days;

        return $diff->invert === 1 && $days > 0 ? null : $days;
    }

    /**
     * PH-045: suggest a low-volume maintenance phase when the plan is at least 5 months old and none overlaps the
     * last 5 months. Every date counts as its calendar day in $timeZone, so the time of day of $today or a phase
     * date never moves the window. (Swift compares the raw instants; its tests only use midnights, where the two agree.)
     *
     * @param  list<Phase>  $phases
     */
    public static function maintenanceSuggestion(
        array $phases, DateTimeImmutable $today, DateTimeZone $timeZone = new DateTimeZone('UTC'),
    ): ?MaintenanceSuggestion {
        if ($phases === []) {
            return null;
        }
        $today = self::startOfDay($today, $timeZone);
        $windowStart = self::monthsBefore($today, self::MONTHS_WITHOUT_MAINTENANCE, $timeZone);
        $planStart = min(array_map(fn (Phase $phase) => self::startOfDay($phase->start, $timeZone), $phases));
        if ($planStart > $windowStart) {
            return null;
        }

        foreach ($phases as $phase) {
            // A maintenance with no end (PH-005) is still running. end is exclusive, so a maintenance is inside the
            // window when the day before its end is on or after the window's first day.
            if ($phase->type === PhaseType::Maintenance && self::startOfDay($phase->start, $timeZone) <= $today
                && ($phase->end === null || self::startOfDay($phase->end, $timeZone) > $windowStart)) {
                return null;
            }
        }

        return new MaintenanceSuggestion(weeks: self::SUGGESTED_MAINTENANCE_WEEKS, isResensitization: true);
    }

    private static function startOfDay(DateTimeImmutable $date, DateTimeZone $timeZone): DateTimeImmutable
    {
        return $date->setTimezone($timeZone)->setTime(0, 0);
    }

    /** Calendar month subtraction: a day past the end of the earlier month becomes its last day (31 Jul - 5 = 28 Feb). */
    private static function monthsBefore(DateTimeImmutable $date, int $months, DateTimeZone $timeZone): DateTimeImmutable
    {
        $local = $date->setTimezone($timeZone);
        $firstOfMonth = $local->setDate((int) $local->format('Y'), (int) $local->format('n') - $months, 1);
        $day = min((int) $local->format('j'), (int) $firstOfMonth->format('t'));

        return $firstOfMonth->setDate((int) $firstOfMonth->format('Y'), (int) $firstOfMonth->format('n'), $day);
    }
}
