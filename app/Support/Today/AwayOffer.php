<?php

namespace App\Support\Today;

use App\Domain\Model\Plan as DomainPlan;
use App\Domain\Plan\AwayWeekRow;
use App\Domain\Plan\AwayWeeks;
use App\Domain\Plan\DatedMesocycle;
use App\Domain\Plan\PlanWeekGrid;
use App\Domain\Progress\PlanWeekDays;
use App\Domain\WeekBuilder\AwayWeek\Calories;
use App\Domain\WeekBuilder\AwayWeek\Training;
use App\Models\Mesocycle;
use App\Models\Phase;
use App\Models\Plan;
use App\Models\PlanWeek;
use App\Support\Mesocycles\TrainSnapshot;
use App\Support\Plan\PlanSnapshot;
use DateTimeImmutable;
use Illuminate\Support\Collection;

/**
 * The away-week rules ({@see AwayWeeks}) over the stored rows. The Away sheet, its save and the week strip's menu
 * each read the plan their own way, and all three come here, so they offer the same weeks.
 */
final class AwayOffer
{
    /** The offer the plan snapshot of a write or of the Away sheet sees. */
    public static function fromSnapshot(PlanSnapshot $plan): AwayWeeks
    {
        return self::from($plan->plan, $plan->phases, $plan->mesocycles, $plan->planWeeks, $plan->today);
    }

    /** The offer the week strip sees; null without a plan. */
    public static function fromTrain(TrainSnapshot $train): ?AwayWeeks
    {
        return $train->plan === null ? null : self::from($train->plan, $train->phases, $train->saved(), $train->planWeeks, $train->today);
    }

    /**
     * @param  Collection<int, Phase>  $phases  sorted by start
     * @param  Collection<int, Mesocycle>  $mesocycles  the saved ones
     * @param  Collection<int, PlanWeek>  $planWeeks
     */
    private static function from(Plan $plan, Collection $phases, Collection $mesocycles, Collection $planWeeks, DateTimeImmutable $today): AwayWeeks
    {
        $domain = new DomainPlan(
            id: (string) $plan->id,
            start: PlanSnapshot::day($plan->start_date),
            reviewWeekday: PlanWeekDays::calendarWeekdayFromIso($plan->review_weekday),
            phases: $phases->map(fn (Phase $phase) => PlanSnapshot::domainPhase($phase))->values()->all(),
        );
        $grid = PlanWeekGrid::forPlan($domain);
        $rows = [];
        foreach ($planWeeks as $week) {
            $index = $grid->weekIndexContaining(PlanSnapshot::day($week->start_date));
            if ($index !== null) {
                $rows[$index] = new AwayWeekRow(
                    isAway: (bool) $week->is_away,
                    training: Training::tryFrom((string) $week->away_training),
                    calories: Calories::tryFrom((string) $week->away_calories),
                    isDietBreak: (bool) $week->is_diet_break,
                    keepPhaseEnd: (bool) $week->keep_phase_end,
                );
            }
        }

        return new AwayWeeks(
            $domain,
            $grid,
            $today,
            $mesocycles->map(fn (Mesocycle $m) => new DatedMesocycle(PlanSnapshot::day($m->start_date), (int) $m->weeks))->values()->all(),
            $rows,
        );
    }

    /** What the contract calls the calories choice. */
    public static function apiCalories(Calories $calories): string
    {
        return $calories === Calories::RestDays ? 'rest' : 'maintenance';
    }

    public static function calories(string $api): Calories
    {
        return $api === 'rest' ? Calories::RestDays : Calories::Maintenance;
    }
}
