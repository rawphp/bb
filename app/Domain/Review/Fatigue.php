<?php

namespace App\Domain\Review;

use App\Domain\Defaults\TBL051ReviewThresholds;
use App\Domain\Model\PhaseType;
use App\Domain\Model\PlanWeek;
use App\Domain\Model\Soreness;
use App\Domain\Model\VolumeFeedback;

/** Fatigue judged from training and diet (REV-040 to REV-044, REV-011, TBL-051). */
final class Fatigue
{
    /** REV-011: the deload this many weeks away or fewer is moved forward, with no question. */
    public const DELOAD_NOW_WITHIN_WEEKS = 2;

    /** REV-011: "Yes" is the default once this many training weeks are done. */
    public const DELOAD_TOO_DEFAULT_YES_AFTER_WEEKS = 3;

    /** REV-040: training fatigue for the week. SCH-061: an away week counts nothing. */
    public static function training(FatigueWeek $week): TrainingFatigue
    {
        if ($week->week->isAway) {
            return new TrainingFatigue(0, 0, 0, 0);
        }
        $sore = [];
        $volume = [];
        foreach ($week->feedback as $sheet) {
            if ($sheet->soreness !== null) {
                $sore[] = $sheet->soreness;
            }
            if ($sheet->volume !== null) {
                $volume[] = $sheet->volume;
            }
        }
        $bad = count(array_filter($sore, fn (Soreness $s) => $s === Soreness::StillSore))
            + count(array_filter($volume, fn (VolumeFeedback $v) => $v === VolumeFeedback::TooMuch));

        return new TrainingFatigue(
            underperformingMuscles: count(array_unique(array_map(fn ($m) => $m->value, $week->underperformingMuscles))),
            stillSoreOrTooMuchAnswers: $bad,
            soreAndVolumeAnswers: count($sore) + count($volume),
            skippedSessions: $week->skippedSessions,
        );
    }

    /**
     * REV-040: diet fatigue. `$weeks` are the phase's finished weeks, oldest first.
     *
     * @param  list<FatigueWeek>  $weeks
     */
    public static function diet(PhaseType $phase, array $weeks, bool $isStalled): DietFatigue
    {
        return new DietFatigue(
            deficitWeeksSinceBreak: $phase->isFatLoss() ? self::weeksSinceBreak($weeks) : 0,
            isStalled: $isStalled,
            loggedDaysDrop: self::loggedDaysDrop($weeks),
        );
    }

    /**
     * REV-041 to REV-044: what to recommend for next week. Null when nothing changes.
     *
     * @param  list<FatigueWeek>  $weeks  oldest first
     */
    public static function advice(PhaseType $phase, array $weeks, PlanWeek $nextWeek): ?FatigueAdvice
    {
        // REV-044: outside Fat loss fatigue never changes the diet.
        $newest = $weeks === [] ? null : $weeks[count($weeks) - 1];
        if (! $phase->isFatLoss() || $newest === null || $newest->isBreak()) {
            return null;
        }
        // REV-010 hides only Drop fatigue when next week is already a diet break or deload.
        $dropAllowed = ! $nextWeek->isDietBreak && ! $nextWeek->isDeload;

        if (self::training($newest)->isHigh()) {
            if (self::staysHighAfterBreak($weeks)) {
                return FatigueAdvice::endFatLoss();
            }

            return $dropAllowed ? FatigueAdvice::dropFatigue(DropFatigueReason::TrainingFatigue) : null;
        }
        if (! $dropAllowed) {
            return null;
        }
        if (self::weeksSinceBreak($weeks) >= TBL051ReviewThresholds::DIET_FATIGUE_DEFICIT_WEEKS) {
            return FatigueAdvice::dropFatigue(DropFatigueReason::WeeksSinceBreak);
        }

        return null;
    }

    /** REV-011: `$weeksDone` counts the training weeks finished, including the week just reviewed. */
    public static function deloadOffer(int $mesocycleWeeks, int $weeksDone): DeloadOffer
    {
        if ($mesocycleWeeks - $weeksDone <= self::DELOAD_NOW_WITHIN_WEEKS) {
            return DeloadOffer::deloadNow();
        }

        return DeloadOffer::askDeloadToo($weeksDone >= self::DELOAD_TOO_DEFAULT_YES_AFTER_WEEKS);
    }

    /**
     * Trailing weeks, newest back, that are not a diet break or deload.
     *
     * @param  list<FatigueWeek>  $weeks
     */
    private static function weeksSinceBreak(array $weeks): int
    {
        $count = 0;
        for ($i = count($weeks) - 1; $i >= 0 && ! $weeks[$i]->isBreak(); $i--) {
            $count++;
        }

        return $count;
    }

    /** @param  list<FatigueWeek>  $weeks */
    private static function loggedDaysDrop(array $weeks): ?float
    {
        $count = TBL051ReviewThresholds::DIET_FATIGUE_LOGGED_DAYS_AVERAGE_WEEKS;
        if (count($weeks) <= $count) {
            return null;
        }
        $newest = $weeks[count($weeks) - 1];
        $earlier = array_slice($weeks, -$count - 1, $count);
        $average = array_sum(array_map(fn (FatigueWeek $w) => $w->loggedDays, $earlier)) / $count;

        return $average - $newest->loggedDays;
    }

    /**
     * REV-043: the newest week is high; the weeks right before it are a diet break run holding a deload;
     * the week before that run was high too. SCH-061: away weeks that are not breaks count nothing, so they are skipped.
     *
     * @param  list<FatigueWeek>  $weeks
     */
    private static function staysHighAfterBreak(array $weeks): bool
    {
        $earlier = array_values(array_filter(
            array_slice($weeks, 0, -1),
            fn (FatigueWeek $w) => $w->isBreak() || ! $w->week->isAway,
        ));
        $index = count($earlier) - 1;
        $sawDeload = false;
        $sawBreak = false;
        while ($index >= 0 && $earlier[$index]->isBreak()) {
            $sawBreak = true;
            $sawDeload = $sawDeload || $earlier[$index]->week->isDeload;
            $index--;
        }

        return $sawBreak && $sawDeload && $index >= 0 && self::training($earlier[$index])->isHigh();
    }
}
