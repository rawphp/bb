<?php

namespace App\Domain\Review;

use App\Domain\Macros\MoveRefusal;
use App\Domain\Macros\TargetRefusalReason;
use App\Domain\Model\PlanWeek;
use App\Domain\Review\NextWeekCalories\Kind;
use App\Domain\WeekBuilder\BuiltWeek;
use App\Domain\WeekBuilder\CalorieMove;
use App\Domain\WeekBuilder\WeekBuilder;
use App\Domain\WeekBuilder\WeekBuildRefusal;
use Closure;
use DateTimeImmutable;

/**
 * The week a review sets (REV-002, REV-004, REV-010, REV-011, REV-013), built by WeekBuilder, which holds the day
 * types, targets, meals and workouts. Days are read in the time zone of the plan start.
 */
final class ReviewNextWeek
{
    /** REV-002: a diet break, deload or away week already on the plan keeps its own rules. */
    public static function isPlanned(PlanWeek $week): bool
    {
        return $week->isDeload || $week->isDietBreak || $week->isAway;
    }

    /**
     * Next week for a calorie choice. A chosen average that the macro rules refuse (SAFE-008 to SAFE-012) throws a
     * targets refusal. At BMI 17 or less the phase's own cut is refused, so the change starts from maintenance (SAFE-011).
     *
     * @param  list<CalorieMove>  $moves
     * @param  ?BuiltWeek  $planned  this world's planned week when the caller already has it, to save building it again
     *
     * @throws WeekBuildRefusal
     */
    public static function build(ReviewWorld $world, NextWeekCalories $calories, array $moves = [], ?BuiltWeek $planned = null): BuiltWeek
    {
        switch ($calories->kind) {
            case Kind::Planned:
                if ($moves === [] && $planned !== null) {
                    return $planned;
                }

                return self::make($world, false, 0, $moves);
            case Kind::DietBreak:
                return self::make($world, true, 0, $moves);
            case Kind::Average:
                $dietBreak = false;
                try {
                    $base = $planned ?? self::make($world, false, 0, []);
                } catch (WeekBuildRefusal $refusal) {
                    if ($refusal->targets?->reason !== TargetRefusalReason::BmiAtOrBelowFloor) {
                        throw $refusal;
                    }
                    $dietBreak = true;
                    $base = self::make($world, true, 0, []);
                }
                $average = BuiltWeekSummary::averageKcal($base);
                $change = $average === null ? 0 : (float) $calories->kcal - $average;

                return self::make($world, $dietBreak, $change, $moves);
        }
    }

    /**
     * REV-002: the targets "Repeat this week" gives next week. A week that is planned keeps its own rules.
     *
     * @throws WeekBuildRefusal
     */
    public static function repeatWeek(ReviewWorld $world, ?float $repeatKcal, ?BuiltWeek $planned = null): BuiltWeek
    {
        $planned = self::build($world, NextWeekCalories::planned(), [], $planned);
        if (self::isPlanned($planned->week) || $repeatKcal === null) {
            return $planned;
        }

        return self::build($world, NextWeekCalories::average($repeatKcal), [], $planned);
    }

    /**
     * What Save writes. Saved on or before the review day it is the chosen option; saved late, days already past
     * keep the repeated targets and today onward gets the chosen option (REV-002). Days past are locked out of
     * moves (NUT-024).
     *
     * @param  list<CalorieMove>  $moves
     * @param  ?BuiltWeek  $planned  next week as planned, when the caller has it. Only its dates are read here, so it
     *                               may come from the world before a deload moved.
     * @param  ?ReviewWorld  $repeatWorld  the world days already past are repeated in, when $world has been changed by
     *                                     the choice (a deload moved by Drop fatigue): those days were lived in the
     *                                     world as it stood.
     * @param  DateTimeImmutable  $today  read in the plan's time zone
     *
     * @throws WeekBuildRefusal
     */
    public static function forSave(
        ReviewWorld $world,
        NextWeekCalories $calories,
        ?float $repeatKcal,
        array $moves,
        DateTimeImmutable $today,
        ?BuiltWeek $planned = null,
        ?ReviewWorld $repeatWorld = null,
    ): BuiltWeek {
        $dates = $planned ?? self::build($world, NextWeekCalories::planned());
        $startOfToday = $today->setTimezone($world->plan->start->getTimezone())->setTime(0, 0);
        $pastCount = count(array_filter($dates->days, fn ($day) => $day->day->date->setTime(0, 0) < $startOfToday));
        $locked = array_filter(
            array_merge(...array_map(fn (CalorieMove $move) => [$move->from, $move->to], $moves)),
            fn (int $index) => $index < $pastCount,
        );
        if ($locked !== []) {
            throw WeekBuildRefusal::move(MoveRefusal::dayCompleted(min($locked)));
        }
        $chosen = self::build($world, $calories, $moves, $planned);
        if ($pastCount === 0) {
            return $chosen;
        }
        $repeated = self::repeatWeek($repeatWorld ?? $world, $repeatKcal, $planned);
        $days = $chosen->days;
        for ($i = 0; $i < min($pastCount, count($days), count($repeated->days)); $i++) {
            $days[$i] = $repeated->days[$i];
        }

        return new BuiltWeek($chosen->week, $chosen->phase, $days);
    }

    /** @param  list<CalorieMove>  $moves */
    private static function make(ReviewWorld $world, bool $dietBreak, float $change, array $moves): BuiltWeek
    {
        return WeekBuilder::build(
            plan: $world->plan, profile: $world->profile, mesocycle: $world->mesocycle,
            mesocycleStartWeek: $world->mesocycleStartWeek, week: $world->nextWeek, schedule: $world->schedule,
            newId: $world->newId ?? self::counter(), away: $world->away,
            isDietBreak: $dietBreak || $world->storedDietBreak, flaggedDeload: $world->storedDeload,
            dietBreakTurnedOff: $world->dietBreakTurnedOff, calorieChangePerDay: $change, moves: $moves,
            pausedWeeks: $world->pausedWeeks, otherActivities: $world->otherActivities,
        );
    }

    /** @return Closure(): string */
    private static function counter(): Closure
    {
        $next = 0;

        return function () use (&$next): string {
            return 'n'.++$next;
        };
    }
}
