<?php

namespace App\Domain\Plan;

use App\Domain\Model\Meal;
use App\Domain\Model\Mesocycle;
use App\Domain\Model\Plan;
use App\Domain\Model\Profile;
use App\Domain\Retarget\OtherActivity;
use App\Domain\WeekBuilder\AwayWeek;
use App\Domain\WeekBuilder\MealNames;
use App\Domain\WeekBuilder\WeekBuilder;
use App\Domain\WeekBuilder\WeekBuildRefusal;
use App\Domain\WeekBuilder\WeekDay;
use App\Domain\WeekBuilder\WeekMeal;
use App\Domain\WeekBuilder\WeekMealKind;
use App\Domain\WeekBuilder\WeekSchedule;
use App\Domain\WeekBuilder\WeekSessions\SessionRow;
use Closure;
use DateTimeImmutable;

/**
 * SET-004: a change to the profile or schedule recalculates the targets from the next day. The week is built with
 * WeekBuilder and the days after today are kept; today and earlier days keep their targets.
 */
final class PlanRebuild
{
    /**
     * The plan week to rebuild: the one holding today. Before the plan starts, its first week when asked (SAFE-017:
     * a phase switched from the first week needs that week's targets to follow it), else refused.
     *
     * @throws PlanRebuildRefused
     */
    public static function week(Plan $plan, DateTimeImmutable $today, bool $beforeStartBuildsFirstWeek = false): int
    {
        $grid = PlanWeekGrid::forPlan($plan);
        $week = $grid->weekIndexContaining($today)
            ?? ($beforeStartBuildsFirstWeek ? 0 : throw new PlanRebuildRefused(PlanRebuildRefusal::planNotStarted()));
        if (PlanRules::phaseIndexCoveringWeek($week, $plan, $grid) === null) {
            throw new PlanRebuildRefused(PlanRebuildRefusal::noPhase());
        }

        return $week;
    }

    /**
     * SET-004: builds the week and returns its days after today that carry targets, each meal under the id it is saved
     * with: a regular meal takes the stored regular meal's id at its place in the order, the intra-workout shake takes the
     * stored shake's id (NUT-030), else the built one. A training-only profile (SAFE-003)
     * builds no targets, so it returns none.
     *
     * @param  list<int>  $pausedWeeks
     * @param  list<SessionRow>  $sessionRows
     * @param  array<string, OtherActivity>  $otherActivities  keyed by Y-m-d
     * @param  array<string, list<array{id: string, name: string}>>  $storedMeals  each stored day's meals in stored order, keyed by Y-m-d
     * @param  Closure(): string  $newId
     * @return list<WeekDay>
     *
     * @throws PlanRebuildRefused
     */
    public static function days(
        Plan $plan,
        Profile $profile,
        Mesocycle $mesocycle,
        int $mesocycleStartWeek,
        int $week,
        WeekSchedule $schedule,
        DateTimeImmutable $today,
        Closure $newId,
        ?AwayWeek $away = null,
        bool $isDietBreak = false,
        bool $flaggedDeload = false,
        bool $dietBreakTurnedOff = false,
        float $calorieChangePerDay = 0,
        array $pausedWeeks = [],
        array $sessionRows = [],
        array $otherActivities = [],
        array $storedMeals = [],
    ): array {
        try {
            $built = WeekBuilder::build(
                plan: $plan, profile: $profile, mesocycle: $mesocycle, mesocycleStartWeek: $mesocycleStartWeek, week: $week,
                schedule: $schedule, newId: $newId, away: $away, isDietBreak: $isDietBreak, flaggedDeload: $flaggedDeload,
                dietBreakTurnedOff: $dietBreakTurnedOff, calorieChangePerDay: $calorieChangePerDay,
                pausedWeeks: $pausedWeeks, sessionRows: $sessionRows, otherActivities: $otherActivities,
            );
        } catch (WeekBuildRefusal $refusal) {
            throw new PlanRebuildRefused(PlanRebuildRefusal::fromWeekBuild($refusal));
        }

        $after = $today->format('Y-m-d');
        $days = [];
        foreach ($built->days as $day) {
            $date = $day->day->date->format('Y-m-d');
            if ($day->target === null || $date <= $after) {
                continue;
            }
            // Built meals are in time order and stored ones in stored order (a shake is stored last), so the order
            // cannot match them up: regular meals go by their order among the regular ones, the shake by its kind.
            $regularIds = [];
            $shakeId = null;
            foreach ($storedMeals[$date] ?? [] as $stored) {
                if ($stored['name'] === MealNames::INTRA_WORKOUT_SHAKE) {
                    $shakeId ??= $stored['id'];
                } else {
                    $regularIds[] = $stored['id'];
                }
            }
            $ordinal = 0;
            $days[] = new WeekDay(
                $day->day,
                $day->workouts,
                array_map(function (WeekMeal $meal) use (&$ordinal, $regularIds, $shakeId) {
                    $id = $meal->kind === WeekMealKind::IntraWorkoutShake ? $shakeId : ($regularIds[$ordinal++] ?? null);

                    return new WeekMeal(new Meal($id ?? $meal->id(), $meal->meal->name, $meal->meal->target), $meal->time, $meal->kind);
                }, $day->meals),
                $day->target,
                $day->carbBoundsWarning,
            );
        }

        return $days;
    }
}
