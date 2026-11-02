<?php

namespace Tests\Unit\Domain\Retarget;

use App\Domain\Energy\EnergyRules;
use App\Domain\Energy\EnergyWorkout;
use App\Domain\Model\Day;
use App\Domain\Model\ExerciseSlot;
use App\Domain\Model\Meal;
use App\Domain\Model\MealTarget;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\PlanWeek;
use App\Domain\Model\Session;
use App\Domain\Placement\ClockRange;
use App\Domain\Placement\ClockTime;
use App\Domain\Placement\WorkoutTime;
use App\Domain\Retarget\OtherActivity;
use App\Domain\Retarget\RetargetContext;
use App\Domain\Retarget\RetargetDay;
use App\Domain\Retarget\RetargetMeal;
use App\Domain\Retarget\RetargetRefusal;
use App\Domain\Retarget\RetargetRefused;
use App\Domain\WeekBuilder\WeekMealKind;
use App\Domain\WeekBuilder\WeekWorkout;
use Closure;
use DateTimeImmutable;
use Tests\Unit\Domain\Plan\PlanFixtures;

/**
 * Shared inputs for the Retarget tests, the same as rp-clone's RetargetTests Fixture: a 90 kg user, eating window
 * 06:00 to 22:00, five meals, on Monday 2026-01-05 (UTC).
 */
final class RetargetFixtures
{
    public const ACC = 1e-6;

    private static int $nextMeal = 0;

    public static function monday(): DateTimeImmutable
    {
        return PlanFixtures::date(2026, 1, 5);
    }

    public static function wednesday(): DateTimeImmutable
    {
        return PlanFixtures::date(2026, 1, 7);
    }

    public static function week(): PlanWeek
    {
        return new PlanWeek(self::monday());
    }

    public static function window(): ClockRange
    {
        return new ClockRange(ClockTime::at(6), ClockTime::at(22));
    }

    public static function context(): RetargetContext
    {
        return new RetargetContext(weightKg: 90, eatingWindow: self::window());
    }

    /** 180 g protein, 80 g fat, 300 g carbs: 2,640 kcal. */
    public static function dayTarget(): MealTarget
    {
        return new MealTarget(kcal: 2640, proteinG: 180, fatG: 80, carbsG: 300);
    }

    /** An id source for the meals a re-target creates: n1, n2, ... */
    public static function newId(): Closure
    {
        $next = 0;

        return function () use (&$next): string {
            return 'n'.++$next;
        };
    }

    public static function session(int $sets = 8, ?ClockTime $start = null): WeekWorkout
    {
        $minutes = EnergyRules::sessionMinutes($sets);

        return new WeekWorkout(
            session: new Session('upper-a', 'Upper A', [new ExerciseSlot('bench', 'Bench press', MuscleGroup::Chest, $sets)]),
            time: new WorkoutTime($start ?? ClockTime::at(17, 30), $minutes),
            workingSets: $sets,
            intensity: EnergyRules::sessionIntensity($sets, (float) $minutes, false),
        );
    }

    /** A meal with a fresh id; the flags are what the user has done to it. */
    public static function meal(
        int $hour,
        int $minute = 0,
        WeekMealKind $kind = WeekMealKind::Regular,
        float $kcal = 500,
        string $name = 'Meal',
        ?MealTarget $target = null,
        bool $checkedIn = false,
        bool $locked = false,
        bool $handEditedTarget = false,
        bool $handSetTime = false,
    ): RetargetMeal {
        return new RetargetMeal(
            meal: new Meal('meal-'.++self::$nextMeal, $name, $target ?? new MealTarget(kcal: $kcal, proteinG: 35, fatG: 15, carbsG: 60)),
            time: ClockTime::at($hour, $minute),
            kind: $kind,
            isCheckedIn: $checkedIn,
            isLocked: $locked,
            hasHandEditedTarget: $handEditedTarget,
            hasHandSetTime: $handSetTime,
        );
    }

    /**
     * A training day around a 17:30 session, or with `$session` instead.
     *
     * @param  ?list<RetargetMeal>  $meals
     */
    public static function day(
        ?DateTimeImmutable $date = null,
        ?WeekWorkout $session = null,
        ?OtherActivity $activity = null,
        ?array $meals = null,
    ): RetargetDay {
        return self::build($date, $session ?? self::session(), $activity, $meals);
    }

    /**
     * A day with no session.
     *
     * @param  ?list<RetargetMeal>  $meals
     */
    public static function restDay(?DateTimeImmutable $date = null, ?OtherActivity $activity = null, ?array $meals = null): RetargetDay
    {
        return self::build($date, null, $activity, $meals);
    }

    /** @param  ?list<RetargetMeal>  $meals */
    private static function build(?DateTimeImmutable $date, ?WeekWorkout $session, ?OtherActivity $activity, ?array $meals): RetargetDay
    {
        $meals ??= [
            self::meal(7), self::meal(10), self::meal(13),
            self::meal(16, kind: $session === null ? WeekMealKind::Regular : WeekMealKind::PreWorkout),
            self::meal(19, kind: $session === null ? WeekMealKind::Regular : WeekMealKind::PostWorkout),
        ];
        $workouts = array_values(array_filter([
            $session === null ? null : new EnergyWorkout((float) $session->time->lengthMinutes, $session->intensity),
            $activity === null ? null : new EnergyWorkout((float) $activity->minutes, $activity->intensity),
        ]));
        $type = EnergyRules::dayWorkout($workouts, 90)->dayType;

        return new RetargetDay(
            day: new Day($date ?? self::monday(), $type),
            session: $session,
            otherActivity: $activity,
            meals: $meals,
            target: self::dayTarget(),
        );
    }

    /** @param  list<RetargetMeal>  $meals */
    public static function kcal(array $meals): float
    {
        return array_sum(array_map(fn (RetargetMeal $m) => $m->meal->target->kcal, $meals));
    }

    /** @param  list<RetargetMeal>  $meals */
    public static function carbs(array $meals): float
    {
        return array_sum(array_map(fn (RetargetMeal $m) => $m->meal->target->carbsG, $meals));
    }

    /** @param  list<RetargetMeal>  $meals */
    public static function protein(array $meals): float
    {
        return array_sum(array_map(fn (RetargetMeal $m) => $m->meal->target->proteinG, $meals));
    }

    public static function mealOfKind(WeekMealKind $kind, RetargetDay $day): ?RetargetMeal
    {
        foreach ($day->meals as $meal) {
            if ($meal->kind === $kind) {
                return $meal;
            }
        }

        return null;
    }

    public static function mealWithId(string $id, RetargetDay $day): ?RetargetMeal
    {
        foreach ($day->meals as $meal) {
            if ($meal->id() === $id) {
                return $meal;
            }
        }

        return null;
    }

    /**
     * @param  list<RetargetMeal>  $meals
     * @return list<int>
     */
    public static function minutes(array $meals): array
    {
        return array_map(fn (RetargetMeal $m) => $m->time->minuteOfDay, $meals);
    }

    /**
     * @param  list<RetargetMeal>  $meals
     * @return list<string>
     */
    public static function ids(array $meals): array
    {
        return array_map(fn (RetargetMeal $m) => $m->id(), $meals);
    }

    /** The refusal a call threw, or null when it threw none. */
    public static function refusal(Closure $call): ?RetargetRefusal
    {
        try {
            $call();
        } catch (RetargetRefused $refused) {
            return $refused->refusal;
        }

        return null;
    }
}
