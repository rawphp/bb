<?php

namespace App\Domain\Review\CalorieReview;

use App\Domain\Defaults\TBL050OnTargetBand;
use App\Domain\Macros\MacroRules;
use App\Domain\Macros\MacroTargets;
use App\Domain\Macros\TargetRefusal;
use App\Domain\Macros\TargetRefusalReason;
use App\Domain\Macros\WeekTargets;
use App\Domain\Model\Phase;
use App\Domain\Model\PhaseType;
use App\Domain\Model\PlanWeek;
use App\Domain\Model\Profile;
use App\Domain\Phases\MaintenanceHold;
use App\Domain\Progress\Days;
use App\Domain\Review\CalorieOptions;
use App\Domain\Review\CalorieReview;
use App\Domain\Review\MeasuredExpenditure;
use App\Domain\Review\RecommendationReason;
use App\Domain\Review\RecommendationReasonKind;
use App\Domain\Review\RepeatThisWeek;
use App\Domain\Review\ReviewWeek;
use App\Domain\Review\StallChange;
use App\Domain\Review\StallOptions;
use App\Domain\Weight\CurrentWeight;
use App\Domain\Weight\TrendWeight;
use DateTimeImmutable;

/**
 * One calorie review of the newest week.
 *
 * @internal Use CalorieReview::recommend().
 */
final class Review
{
    private readonly float $current;

    /**
     * @param  list<ReviewWeek>  $weeks  oldest first; $week is the last of them
     * @param  float  $expenditureKcal  measured over $weeks (REV-021), else the formula's
     * @param  float  $formulaExpenditureKcal  NUT-002's, for a week before $week whose own measurement has too few weeks
     */
    public function __construct(
        private readonly Phase $phase,
        private readonly array $weeks,
        private readonly ReviewWeek $week,
        private readonly TrendWeight $trend,
        private readonly WeekTargets $currentTargets,
        private readonly Profile $profile,
        private readonly float $expenditureKcal,
        private readonly int $plannedDailySteps,
        private readonly float $formulaExpenditureKcal,
    ) {
        $this->current = $week->targetKcal;
    }

    // Decision

    public function decide(): Decision
    {
        // SAFE-011: at or below BMI 17 fat loss stops, whatever the week looked like. Raise to maintenance,
        // never below this week's target.
        if ($this->phase->type->isFatLoss()) {
            $bmi = MacroTargets::bmi($this->profile);
            if ($bmi !== null && $bmi <= MacroRules::BMI_CUT_FLOOR) {
                return new Decision(
                    max($this->expenditureKcal, $this->current),
                    RecommendationReason::safety(TargetRefusal::bmiAtOrBelowFloor($bmi)),
                );
            }
        }
        // SCH-064: a rough week repeats, whatever else it shows (REV-041 and REV-046 included).
        if ($this->week->isRough) {
            return new Decision(RepeatThisWeek::targetKcal($this->weeks) ?? $this->current, RecommendationReason::roughWeek());
        }
        $decision = $this->choose();
        // REV-030: the stall options were checked as they were built.
        if ($decision->reason->kind === RecommendationReasonKind::Stalled) {
            return $decision;
        }

        return $this->withSafety($decision);
    }

    private function choose(): Decision
    {
        // REV-023 exception: the week after a diet break goes straight back, uncapped, with no water hold.
        // PLAN-022: a fat loss deload runs at maintenance, so it is a diet break however the week is marked.
        // REV-046: when the last ordinary week lost too fast with the lifts paying for it, back to its corrected target.
        if (CalorieOptions::isDietBreak($this->week->week, $this->phase->type)) {
            $last = $this->lastNormalWeekBeforeThis();
            if ($last !== null) {
                return new Decision($this->correctedTarget($last) ?? $last->targetKcal, RecommendationReason::returnFromBreak());
            }
        }
        // REV-025.
        if ($this->week->loggedDays() < MeasuredExpenditure::MINIMUM_LOGGED_DAYS || $this->isOffTarget()) {
            return new Decision(
                $this->current, RecommendationReason::poorAdherence($this->week->loggedDays(), $this->week->averageIntakeKcal()),
            );
        }
        // REV-032.
        if ($this->isFirstPhaseWeek($this->week)) {
            return new Decision($this->current, RecommendationReason::firstWeekOfPhase());
        }

        $rate = $this->rate($this->week);
        if ($rate === null) {
            return new Decision($this->current, RecommendationReason::noTrend());
        }
        // REV-046: back to the target rate in one step, uncapped. It raises calories, so no water hold.
        if ($this->isLosingTooFast($this->week)) {
            return new Decision($this->level(), RecommendationReason::losingTooFast());
        }

        if ($this->phase->type === PhaseType::Maintenance) {
            return $this->maintenance();
        }
        if ($this->phase->type->isFatLoss() && $this->isStalled()) {
            return $this->stall();
        }
        if ($this->phase->type === PhaseType::MuscleGain) {
            $decision = $this->muscleGain($rate);
            if ($decision !== null) {
                return $decision;
            }
        }
        if (abs($rate - $this->targetRate()) <= $this->band()) { // REV-024
            return new Decision($this->current, RecommendationReason::onTarget());
        }

        $delta = $this->capped($this->level() - $this->current);
        if ($delta < 0 && $this->hasWater()) {
            return new Decision($this->current, RecommendationReason::waterWeight());
        }

        return $this->move($delta, RecommendationReason::towardTargetRate());
    }

    /** The newest week before the reviewed one that is not a break, deload or away week. */
    private function lastNormalWeekBeforeThis(): ?ReviewWeek
    {
        return RepeatThisWeek::lastOrdinaryBeforeNewest($this->weeks);
    }

    /** REV-025: the average consumed is more than 10% off target, or nothing was logged. */
    private function isOffTarget(): bool
    {
        $average = $this->week->averageIntakeKcal();
        if ($average === null) {
            return true;
        }

        return abs($average - $this->current) > CalorieReview::ADHERENCE_TOLERANCE_SHARE * $this->current;
    }

    private function move(float $delta, RecommendationReason $reason): Decision
    {
        return new Decision($this->current + $delta, $delta == 0 ? RecommendationReason::changeTooSmall() : $reason);
    }

    // Numbers

    /** SAFE-005: the target rate, never faster than the fat loss limit. */
    private function targetRate(): float
    {
        return MacroRules::targetRatePercent($this->phase);
    }

    private function trendKg(): float
    {
        return $this->trendKgAt($this->week);
    }

    /** The trend weight on a week's last day, else the latest, else the profile's weight. */
    private function trendKgAt(ReviewWeek $week): float
    {
        $end = $week->week->end();

        return CurrentWeight::kg(
            ($end === null ? null : $this->trend->trendKg($end)) ?? $this->trend->currentKg(),
            $this->profile->weightKg,
        );
    }

    /** REV-023: the calorie level that hits the target rate from the expenditure. */
    private function level(): float
    {
        return $this->expenditureKcal + $this->kcalForRate($this->targetRate());
    }

    /** REV-046: Fat loss, the week's rate faster than the target band (TBL-050), and its training fatigue high. */
    private function isLosingTooFast(ReviewWeek $week): bool
    {
        if (! $this->phase->type->isFatLoss() || ! $week->trainingFatigueHigh) {
            return false;
        }
        $rate = $this->rate($week);

        return $rate !== null && $rate < $this->targetRate() - $this->band();
    }

    /**
     * REV-046: the target the rule set in an earlier week, recomputed from that week's rows: expenditure measured over
     * the weeks up to it (else the formula's) plus the calories for the target rate at its trend weight. Null when the
     * rule did not apply there.
     */
    private function correctedTarget(ReviewWeek $week): ?float
    {
        $index = array_search($week, $this->weeks, true);
        if ($index === false || ! $this->isLosingTooFast($week)) {
            return null;
        }
        $measured = MeasuredExpenditure::estimate(array_slice($this->weeks, 0, $index + 1), $this->trend);

        return ($measured ?? $this->formulaExpenditureKcal) + MacroRules::dailyKcal($this->targetRate(), $this->trendKgAt($week));
    }

    /** The one-week correction the observed rate implies; only a fallback for a direction rule. */
    private function gap(float $rate): float
    {
        return $this->kcalForRate($this->targetRate() - $rate);
    }

    private function kcalForRate(float $ratePercent): float
    {
        return MacroRules::dailyKcal($ratePercent, $this->trendKg());
    }

    /** REV-023: at most 250 kcal either way, and nothing under 50. */
    private function capped(float $delta): float
    {
        $limited = min(max($delta, -CalorieReview::MAX_CHANGE_KCAL), CalorieReview::MAX_CHANGE_KCAL);

        return abs($limited) < CalorieReview::DEAD_ZONE_KCAL ? 0.0 : $limited;
    }

    /** TBL-050: the absolute or the relative band, whichever is wider. */
    private function band(): float
    {
        return max(
            TBL050OnTargetBand::ABSOLUTE_PERCENT_BODYWEIGHT_PER_WEEK,
            TBL050OnTargetBand::RELATIVE_PERCENT_OF_TARGET_RATE / 100 * abs($this->targetRate()),
        );
    }

    private function rate(ReviewWeek $week): ?float
    {
        $end = $week->week->end();

        return $end === null ? null : $this->trend->weeklyRatePercent($end);
    }

    // Phase weeks

    private function phaseStartDay(): DateTimeImmutable
    {
        return $this->phase->start->setTime(0, 0);
    }

    private function isPhaseWeek(ReviewWeek $week): bool
    {
        return $week->week->start->setTime(0, 0) >= $this->phaseStartDay();
    }

    /** REV-032: the week that starts with the phase (or the first one after it). */
    private function isFirstPhaseWeek(ReviewWeek $week): bool
    {
        if (! $this->isPhaseWeek($week)) {
            return false;
        }
        $firstWeekEnd = $this->phaseStartDay()->modify('+'.PlanWeek::LENGTH_IN_DAYS.' days');

        return $week->week->start->setTime(0, 0) < $firstWeekEnd;
    }

    // Stall (REV-027, REV-028)

    /** The reviewed week and the one before both moved less than 25% of the target rate in its direction. */
    private function isStalled(): bool
    {
        if (! ($this->phase->type->isFatLoss() || $this->phase->type === PhaseType::MuscleGain) || count($this->weeks) <= 1) {
            return false;
        }
        $previous = $this->weeks[count($this->weeks) - 2];
        $threshold = CalorieReview::STALL_SHARE_OF_TARGET_RATE * abs($this->targetRate());
        foreach ([$previous, $this->week] as $candidate) {
            if (! $this->isPhaseWeek($candidate) || $this->isFirstPhaseWeek($candidate) || $candidate->isBreakOrAway()) {
                return false;
            }
            $rate = $this->rate($candidate);
            if ($rate === null) {
                return false;
            }
            if (($this->phase->type->isFatLoss() ? -$rate : $rate) >= $threshold) {
                return false;
            }
        }

        return true;
    }

    private function stall(): Decision
    {
        if ($this->hasWater()) {
            return new Decision($this->current, RecommendationReason::waterWeight());
        }
        $observed = $this->rate($this->week) ?? 0.0;
        $toLevel = $this->level() - $this->current;
        $cut = $this->capped($toLevel < 0 ? $toLevel : min($this->gap($observed), 0.0));
        $cutKcal = $cut < 0 ? -$cut : null;
        $fatFloorIsClose = false;
        if ($cutKcal !== null) {
            if ($this->cutIsAllowed($cutKcal)) {
                // The cut comes out of fat first; fat that falls short of it has hit the fat floor.
                $fatFloorIsClose = $this->currentTargets->fatCutG($cutKcal) < $cutKcal / MacroRules::KCAL_PER_GRAM_FAT - 1e-6;
            } else {
                $cutKcal = null; // SAFE-012 refuses it (SAFE-011 was handled up front)
            }
        }

        $room = CalorieReview::MAX_RECOMMENDED_STEPS - $this->plannedDailySteps;
        $range = CalorieReview::stepIncreaseRange();
        $step = min($range->upper, $room);
        $addSteps = $step >= $range->lower ? (int) $step : null;

        $kcal = $this->current;
        if ($cutKcal !== null && ! $fatFloorIsClose) {
            $recommended = StallChange::CutCalories;
            $kcal -= $cutKcal;
        } elseif ($addSteps !== null) {
            $recommended = StallChange::AddSteps;
        } elseif ($cutKcal !== null) {
            $recommended = StallChange::CutCalories;
            $kcal -= $cutKcal;
        } else {
            $recommended = StallChange::Hold;
        }

        return new Decision($kcal, RecommendationReason::stalled(new StallOptions($cutKcal, $addSteps, $recommended)));
    }

    /** Whether the macro rules would turn a cut of $amount kcal a day into a target. */
    private function cutIsAllowed(float $amount): bool
    {
        try {
            $this->currentTargets->adjusted(-$amount, $this->profile);

            return true;
        } catch (TargetRefusal) {
            return false;
        }
    }

    private function muscleGain(float $rate): ?Decision
    {
        if ($this->isStalled()) {
            $toLevel = $this->level() - $this->current;
            $delta = $this->capped($toLevel > 0 ? $toLevel : max($this->gap($rate), 0.0));

            return $this->move($delta, RecommendationReason::gainStalled());
        }
        if (! ($rate - $this->targetRate() > $this->band())) {
            return null;
        }
        $toLevel = $this->level() - $this->current;
        $delta = $this->capped($toLevel < 0 ? $toLevel : min($this->gap($rate), 0.0));
        if ($delta < 0 && $this->hasWater()) {
            return new Decision($this->current, RecommendationReason::waterWeight());
        }

        return $this->move($delta, RecommendationReason::gainTooFast());
    }

    // Maintenance (REV-029)

    private function maintenance(): Decision
    {
        $start = $this->startTrendKg();
        if ($start === null) {
            return new Decision($this->current, RecommendationReason::noTrend());
        }
        $weeksIn = max(1, $this->weeksIntoPhase());
        $halfWidth = MaintenanceHold::corridor($start)->upper - $start;
        $targetRate = $this->targetRate();
        // PH-004: a small gain or loss target moves the centre by that rate each week.
        $centre = fn (int $weeks): float => $start * (1 + $targetRate / 100 * $weeks);
        $trendKg = $this->trendKg();
        if (abs($trendKg - $centre($weeksIn)) <= $halfWidth) {
            return new Decision($this->current, RecommendationReason::insideCorridor());
        }

        $toNext = ($centre($weeksIn + 1) - $trendKg) * MacroRules::KCAL_PER_KG / PlanWeek::LENGTH_IN_DAYS;
        $delta = $this->capped($this->expenditureKcal + $toNext - $this->current);
        if ($delta < 0 && $this->hasWater()) {
            return new Decision($this->current, RecommendationReason::waterWeight());
        }

        return $this->move($delta, RecommendationReason::outsideCorridor());
    }

    /** The trend weight at the start of the phase. */
    private function startTrendKg(): ?float
    {
        $start = $this->phaseStartDay();
        $onStart = $this->trend->trendKg($start);
        if ($onStart !== null) {
            return $onStart;
        }
        foreach ($this->trend->points as $point) {
            if ($point->day >= $start) {
                return $point->trendKg;
            }
        }

        return null;
    }

    /** The reviewed week's 1-based number within the phase. */
    private function weeksIntoPhase(): int
    {
        $end = $this->week->week->end();
        if ($end === null) {
            return 1;
        }
        $start = $this->phaseStartDay();
        $after = $end->modify('+1 day');

        return intdiv(Days::whole($start, $after, $start->getTimezone()), PlanWeek::LENGTH_IN_DAYS);
    }

    // Water (REV-026)

    private function hasWater(): bool
    {
        return $this->week->week->isDeload
            || $this->week->week->isDietBreak
            || $this->week->hadHigherCarbDayShift
            || $this->hasReversedJump();
    }

    /**
     * A weigh-in in the week more than 1% above the one before it, with a weigh-in back at or below that
     * earlier weight within 3 days.
     */
    private function hasReversedJump(): bool
    {
        $weighIns = array_values(array_filter($this->trend->points, fn ($point) => $point->isWeighIn));
        $days = $this->week->week->days();
        if ($days === []) {
            return false;
        }
        $first = $days[0];
        $last = $days[count($days) - 1];
        for ($index = 1; $index < count($weighIns); $index++) {
            $point = $weighIns[$index];
            $before = $weighIns[$index - 1]->weightKg;
            if ($point->day < $first || $point->day > $last
                || ! ($point->weightKg > $before * (1 + CalorieReview::WATER_JUMP_PERCENT / 100))) {
                continue;
            }
            $deadline = $point->day->modify('+'.CalorieReview::WATER_REVERSAL_DAYS.' days');
            for ($later = $index + 1; $later < count($weighIns); $later++) {
                if ($weighIns[$later]->day <= $deadline && $weighIns[$later]->weightKg <= $before) {
                    return true;
                }
            }
        }

        return false;
    }

    // Safety (REV-030)

    /** SAFE-011 and SAFE-012: a cut the macro rules refuse becomes maintenance or no change. */
    private function withSafety(Decision $decision): Decision
    {
        if (! ($decision->kcal < $this->current)) {
            return $decision;
        }
        try {
            $this->currentTargets->adjusted($decision->kcal - $this->current, $this->profile);

            return $decision;
        } catch (TargetRefusal $refusal) {
            return match ($refusal->reason) {
                // Raise to maintenance, but never below this week's target: that would still be a cut.
                TargetRefusalReason::BmiAtOrBelowFloor => new Decision(
                    max($this->expenditureKcal, $this->current), RecommendationReason::safety($refusal),
                ),
                TargetRefusalReason::BreaksFloor => new Decision($this->current, RecommendationReason::safety($refusal)),
            };
        }
    }
}
