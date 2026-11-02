<?php

namespace App\Domain\Retarget;

use App\Domain\Energy\EnergyRules;
use App\Domain\Energy\EnergyWorkout;
use App\Domain\Macros\MacroRules;
use App\Domain\MealSplit\CarbPlacement;
use App\Domain\MealSplit\DayEntry;
use App\Domain\MealSplit\MealSplit;
use App\Domain\Model\DayType;
use App\Domain\Model\Meal;
use App\Domain\Model\MealTarget;
use App\Domain\Model\PlanWeek;
use App\Domain\Placement\ClockRange;
use App\Domain\Placement\ClockTime;
use App\Domain\Placement\DayPlacement;
use App\Domain\Placement\MealPlacement;
use App\Domain\Placement\MealRole;
use App\Domain\Placement\MealSlot;
use App\Domain\Placement\PlacedMeal;
use App\Domain\Placement\WorkoutTime;
use App\Domain\WeekBuilder\MealNames;
use App\Domain\WeekBuilder\WeekMealKind;
use App\Domain\WeekBuilder\WeekWorkout;
use Closure;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Re-targets a day's meals when its workout changes (SCH-038, SCH-050 to SCH-057). Composes the energy, split and
 * placement rules and holds none of its own.
 *
 * One rule serves a session that moves to another time (SCH-050), starts at a different time (SCH-052) or runs
 * long or short (SCH-054): the day's unchecked meals are placed again around the session (SCH-042, SCH-043) and
 * split from the new day total. A day that loses its session (SCH-051, SCH-053) keeps its meal times and splits the
 * rest-day total. A change in workout energy moves the day's calories and carbs only; protein and fat are the same
 * every day of the week (NUT-012, NUT-013). Meals that are checked in, locked or have a hand-edited target never
 * change, and a hand-set time never moves (SCH-044, SCH-055).
 *
 * The rules never make an id: a meal they create (the intra-workout shake, an added meal) takes the id it is given.
 * Days are read in the time zone of the dates they carry (the plan week's, for the week's days).
 */
final class Retargeting
{
    /** SCH-054: how far off planned a session's length must be before the day is re-targeted. */
    public const LENGTH_TOLERANCE_MINUTES = 20;

    /**
     * SCH-050, SCH-052: the session now starts at $start. Null when it already did.
     *
     * @param  Closure(): string  $newId  an id for the intra-workout shake the session may earn
     *
     * @throws RetargetRefused
     */
    public static function retime(RetargetDay $day, ClockTime $start, RetargetContext $context, Closure $newId): ?MealRetarget
    {
        $session = $day->session ?? throw new RetargetRefused(RetargetRefusal::NoSession);
        if ($session->time->start->minuteOfDay === $start->minuteOfDay) {
            return null;
        }

        return new MealRetarget(
            days: [self::placed($day, $session->startingAt($start), $context, $newId)],
            undo: [$day],
            message: self::message($start),
        );
    }

    /**
     * SCH-052: the session started at $start. Null when that is within 20 minutes of planned, so an on-time start
     * does not re-target.
     *
     * @param  Closure(): string  $newId
     *
     * @throws RetargetRefused
     */
    public static function started(RetargetDay $day, ClockTime $start, RetargetContext $context, Closure $newId): ?MealRetarget
    {
        $session = $day->session ?? throw new RetargetRefused(RetargetRefusal::NoSession);
        $later = $session->time->start->minutesUntil($start);
        if (min($later, ClockTime::MINUTES_PER_DAY - $later) <= self::LENGTH_TOLERANCE_MINUTES) {
            return null;
        }

        return self::retime($day, $start, $context, $newId);
    }

    /**
     * SCH-054: the session ran $actualMinutes. Null when that is within 20 minutes of planned.
     *
     * @param  Closure(): string  $newId
     *
     * @throws RetargetRefused
     */
    public static function ranFor(RetargetDay $day, int $actualMinutes, RetargetContext $context, Closure $newId): ?MealRetarget
    {
        $session = $day->session ?? throw new RetargetRefused(RetargetRefusal::NoSession);
        if (abs($actualMinutes - $session->time->lengthMinutes) <= self::LENGTH_TOLERANCE_MINUTES) {
            return null;
        }
        // TBL-031 with the actual length: over 75 minutes is Hard.
        $session = $session->runningFor(
            $actualMinutes,
            EnergyRules::sessionIntensity($session->workingSets, (float) $actualMinutes, $context->isDeloadWeek),
        );

        return new MealRetarget(
            days: [self::placed($day, $session, $context, $newId)],
            undo: [$day],
            message: self::message($session->time->start),
        );
    }

    /**
     * SCH-051: the session moves to $destination at $start, inside $week. Both days are re-targeted.
     *
     * @param  Closure(): string  $newId
     *
     * @throws RetargetRefused
     */
    public static function move(
        RetargetDay $source,
        RetargetDay $destination,
        ClockTime $start,
        PlanWeek $week,
        RetargetContext $context,
        Closure $newId,
    ): MealRetarget {
        $session = $source->session ?? throw new RetargetRefused(RetargetRefusal::NoSession);
        if ($destination->session !== null) {
            throw new RetargetRefused(RetargetRefusal::DayHasSession);
        }
        $zone = $week->start->getTimezone();
        $dates = $week->days();
        if (! self::containsDay($dates, $source->day->date, $zone) || ! self::containsDay($dates, $destination->day->date, $zone)) {
            throw new RetargetRefused(RetargetRefusal::OutsidePlanWeek);
        }

        return new MealRetarget(
            days: [
                self::withoutSession($source, $context),
                self::placed($destination, $session->startingAt($start), $context, $newId),
            ],
            undo: [$source, $destination],
            message: self::message($start),
        );
    }

    /**
     * SCH-053: the session is skipped. Its energy leaves the day and goes nowhere else.
     *
     * @throws RetargetRefused
     */
    public static function skip(RetargetDay $day, RetargetContext $context): MealRetarget
    {
        if ($day->session === null) {
            throw new RetargetRefused(RetargetRefusal::NoSession);
        }

        return new MealRetarget(
            days: [self::withoutSession($day, $context)],
            undo: [$day],
            message: 'Meals updated for the skipped workout',
        );
    }

    /**
     * SCH-032, SCH-038, NUT-003, NUT-005: the day's Other activity is now $activity (null removes it). Its energy
     * joins the day (or leaves it) and the day type is the higher of the two. Nothing is placed again: the
     * activity's start time does not affect meals, and peri-workout meals stay around the mesocycle session only.
     */
    public static function otherActivity(RetargetDay $day, ?OtherActivity $activity, RetargetContext $context): MealRetarget
    {
        [$target, $type] = self::total($day, $day->session, $activity, $context);
        $position = $day->session === null
            ? null
            : self::workoutPositionAmong($day->meals, $day->session->time, $context->eatingWindow);
        $result = $day
            ->withOtherActivity($activity)
            ->withDayType($type)
            ->withTarget($target)
            ->withMeals(self::split($day->meals, $position, $target, $context->carbPlacement));
        $name = $activity->name ?? $day->otherActivity->name ?? '';

        return new MealRetarget(
            days: [$result],
            undo: [$day],
            message: $activity === null ? "Meals updated: {$name} removed" : "Meals updated for {$name}",
        );
    }

    /**
     * SCH-031: an extra regular meal at $time, with the id $id. Every meal keeps its time; the day's total, type and
     * session do not change, and the meals not fixed (SCH-055) and the new one share what the fixed ones leave.
     */
    public static function addMeal(RetargetDay $day, ClockTime $time, RetargetContext $context, string $id): MealRetarget
    {
        $window = $context->eatingWindow;
        $added = new RetargetMeal(
            meal: new Meal($id, MealNames::EXTRA_MEAL, new MealTarget(kcal: 0, proteinG: 0, fatG: 0, carbsG: 0)),
            time: $time,
            kind: WeekMealKind::Regular,
        );
        $meals = $day->meals;
        $at = array_find_key($meals, fn (RetargetMeal $meal) => $window->offset($meal->time) > $window->offset($time)) ?? count($meals);
        array_splice($meals, $at, 0, [$added]);

        $position = $day->session === null ? null : self::workoutPositionAmong($meals, $day->session->time, $window);

        return new MealRetarget(
            days: [$day->withMeals(self::split($meals, $position, $day->target, $context->carbPlacement))],
            undo: [$day],
            message: "Meal added at {$time->shortText()}",
        );
    }

    /**
     * SCH-036: the unchecked meals placed again by SCH-040 to SCH-046 around the day's session, and split from the
     * same total. Null when no meal would move.
     *
     * @param  Closure(): string  $newId
     *
     * @throws RetargetRefused
     */
    public static function optimize(RetargetDay $day, RetargetContext $context, Closure $newId): ?MealRetarget
    {
        $result = self::placed($day, $day->session, $context, $newId);
        $times = [];
        foreach ($day->meals as $meal) {
            $times[$meal->id()] ??= $meal->time->minuteOfDay;
        }
        $moved = count($result->meals) !== count($day->meals)
            || array_any($result->meals, fn (RetargetMeal $meal) => ($times[$meal->id()] ?? null) !== $meal->time->minuteOfDay);
        if (! $moved) {
            return null;
        }

        return new MealRetarget(days: [$result], undo: [$day], message: 'Meals optimized');
    }

    /**
     * SCH-057: the options the morning after $missed. $trainingDates are the days of $week that hold a mesocycle
     * session.
     *
     * @param  list<DateTimeImmutable>  $trainingDates
     * @return list<MissedDayOption>
     */
    public static function missedDayOptions(DateTimeImmutable $missed, DateTimeImmutable $today, PlanWeek $week, array $trainingDates): array
    {
        $zone = $week->start->getTimezone();
        $days = $week->days();
        $isTraining = fn (DateTimeImmutable $date) => self::containsDay($trainingDates, $date, $zone);
        $todayIndex = array_find_key($days, fn (DateTimeImmutable $date) => self::isSameDay($date, $today, $zone));
        if (! self::containsDay($days, $missed, $zone) || $todayIndex === null) {
            return [MissedDayOption::skip()];
        }
        if (! $isTraining($days[$todayIndex])) {
            return [MissedDayOption::doItToday(), MissedDayOption::skip()];
        }
        foreach (array_slice($days, $todayIndex + 1) as $date) {
            if (! $isTraining($date)) {
                return [MissedDayOption::nextRestDay($date), MissedDayOption::skip()];
            }
        }

        return [MissedDayOption::skip()];
    }

    // The rule

    /**
     * SCH-050: unchecked meals placed again around $session (SCH-039: or in the window, with none), and every free
     * meal split from the new total. The rule behind retime, ranFor, move and optimize, and
     * TrainingWeekdays::retargetThisWeek.
     *
     * @param  Closure(): string  $newId  an id for the intra-workout shake the session may earn
     *
     * @throws RetargetRefused
     */
    public static function placed(RetargetDay $day, ?WeekWorkout $session, RetargetContext $context, Closure $newId): RetargetDay
    {
        $shakeIndex = array_find_key($day->meals, fn (RetargetMeal $meal) => $meal->kind === WeekMealKind::IntraWorkoutShake);
        $shake = $shakeIndex === null ? null : $day->meals[$shakeIndex];
        $meals = array_values(array_filter($day->meals, fn (RetargetMeal $meal) => $meal->kind !== WeekMealKind::IntraWorkoutShake));
        $slots = array_map(fn (RetargetMeal $meal) => new MealSlot($meal->id(), $meal->keepsTime() ? $meal->time : null), $meals);
        $placement = MealPlacement::place($slots, $context->eatingWindow, $context->busy, $session?->time)
            ?? throw new RetargetRefused(RetargetRefusal::MealsDoNotFit);

        $byId = [];
        foreach ($meals as $meal) {
            $byId[$meal->id()] = $meal;
        }
        $placedMeals = array_map(function (PlacedMeal $placed) use ($byId) {
            $meal = $byId[$placed->id];

            return $meal->isFixed() ? $meal : $meal->atTime($placed->time)->ofKind(self::kindOf($placed->role));
        }, $placement->meals);

        // SCH-043: the shake keeps its time relative to the workout, and goes when the session no longer earns one.
        $position = $session === null ? null : self::workoutPositionIn($placement, $session->time, $context->eatingWindow);
        $newShake = null;
        if ($shake !== null && $shake->keepsTime()) {
            $newShake = $shake;
        } elseif ($session !== null && $placement->shake !== null) {
            if ($shake !== null) {
                $offset = $day->session === null ? 0 : $day->session->time->start->minutesUntil($shake->time);
                $newShake = $shake->atTime($session->time->start->adding($offset));
            } else {
                $newShake = new RetargetMeal(
                    meal: new Meal($newId(), MealNames::INTRA_WORKOUT_SHAKE, new MealTarget(kcal: 0, proteinG: 0, fatG: 0, carbsG: 0)),
                    time: $placement->shake,
                    kind: WeekMealKind::IntraWorkoutShake,
                );
            }
        }
        $workoutAt = $position;
        if ($newShake !== null && $newShake->keepsTime()) {
            // A shake that keeps its time goes where its time puts it; the split gives a shake its share anywhere.
            $window = $context->eatingWindow;
            $at = array_find_key($placedMeals, fn (RetargetMeal $meal) => $window->offset($meal->time) > $window->offset($newShake->time))
                ?? count($placedMeals);
            array_splice($placedMeals, $at, 0, [$newShake]);
            if ($position !== null && $at < $position) {
                $workoutAt = $position + 1;
            }
        } elseif ($newShake !== null && $position !== null) {
            array_splice($placedMeals, $position, 0, [$newShake]);
        }

        [$target, $type] = self::total($day, $session, $day->otherActivity, $context);

        return $day
            ->withSession($session)
            ->withDayType($type)
            ->withTarget($target)
            ->withMeals(self::split($placedMeals, $workoutAt, $target, $context->carbPlacement));
    }

    /**
     * SCH-051, SCH-053: the day without its session. Meals keep their times and share the rest-day total (or the
     * Other activity's); an unchecked shake goes with the session.
     */
    private static function withoutSession(RetargetDay $day, RetargetContext $context): RetargetDay
    {
        $meals = [];
        foreach ($day->meals as $meal) {
            if ($meal->isFixed()) {
                $meals[] = $meal;
            } elseif ($meal->kind !== WeekMealKind::IntraWorkoutShake) {
                $meals[] = $meal->ofKind(WeekMealKind::Regular);
            }
        }
        [$target, $type] = self::total($day, null, $day->otherActivity, $context);

        return $day
            ->withSession(null)
            ->withDayType($type)
            ->withTarget($target)
            ->withMeals(self::split($meals, null, $target, $context->carbPlacement));
    }

    // Parts

    /**
     * NUT-003, SCH-038: the day's total with $session and $activity in place of its current ones. The change in
     * workout energy goes to carbs, as `MacroTargets` gives carbs whatever protein and fat leave.
     *
     * @return array{MealTarget, DayType} the target and the day type
     */
    private static function total(RetargetDay $day, ?WeekWorkout $session, ?OtherActivity $activity, RetargetContext $context): array
    {
        $workouts = fn (?WeekWorkout $session, ?OtherActivity $activity) => array_values(array_filter([
            $session === null ? null : new EnergyWorkout((float) $session->time->lengthMinutes, $session->intensity),
            $activity === null ? null : new EnergyWorkout((float) $activity->minutes, $activity->intensity),
        ]));
        $before = EnergyRules::dayWorkout($workouts($day->session, $day->otherActivity), $context->weightKg);
        $after = EnergyRules::dayWorkout($workouts($session, $activity), $context->weightKg);
        $change = $context->countsWorkoutEnergy ? $after->kcal - $before->kcal : 0.0;

        return [
            new MealTarget(
                kcal: $day->target->kcal + $change,
                proteinG: $day->target->proteinG,
                fatG: $day->target->fatG,
                carbsG: $day->target->carbsG + $change / MacroRules::KCAL_PER_GRAM_CARBS,
            ),
            $after->dayType,
        ];
    }

    /**
     * NUT-030 to NUT-037: per-meal targets for $meals in time order, with the workout before $position. Fixed meals
     * keep their targets; a fixed shake is taken off the total, as the split has no fixed shake.
     *
     * @param  list<RetargetMeal>  $meals
     * @return list<RetargetMeal>
     */
    private static function split(array $meals, ?int $position, MealTarget $total, CarbPlacement $placement): array
    {
        $entries = [];
        $kcal = $total->kcal;
        $protein = $total->proteinG;
        $fat = $total->fatG;
        $carbs = $total->carbsG;
        foreach ($meals as $index => $meal) {
            if ($index === $position) {
                $entries[] = DayEntry::workout();
            }
            if ($meal->kind === WeekMealKind::IntraWorkoutShake && $meal->isFixed()) {
                $kcal -= $meal->meal->target->kcal;
                $protein -= $meal->meal->target->proteinG;
                $fat -= $meal->meal->target->fatG;
                $carbs -= $meal->meal->target->carbsG;
            } elseif ($meal->kind === WeekMealKind::IntraWorkoutShake) {
                $entries[] = DayEntry::intraWorkoutShake($meal->id());
            } else {
                $entries[] = DayEntry::meal($meal->id(), $meal->isFixed() ? $meal->meal->target : null);
            }
        }
        if ($position === count($meals)) {
            $entries[] = DayEntry::workout();
        }

        $targets = [];
        foreach (MealSplit::split(new MealTarget($kcal, $protein, $fat, $carbs), $entries, $placement) as $item) {
            $targets[$item->id] = $item->target;
        }

        return array_map(
            fn (RetargetMeal $meal) => ! $meal->isFixed() && isset($targets[$meal->id()]) ? $meal->withTarget($targets[$meal->id()]) : $meal,
            $meals,
        );
    }

    /**
     * Where the workout sits among the placed meals: between the pre- and post-workout meals; with neither, by the
     * clock, on whichever day puts it nearer the window (as `WeekBuilder` lays a day out).
     */
    private static function workoutPositionIn(DayPlacement $placement, WorkoutTime $workout, ClockRange $window): int
    {
        $placed = $placement->meals;
        $post = array_find_key($placed, fn (PlacedMeal $meal) => $meal->role === MealRole::PostWorkout);
        if ($post !== null) {
            return $post;
        }
        $pre = array_find_key($placed, fn (PlacedMeal $meal) => $meal->role === MealRole::PreWorkout);
        if ($pre !== null) {
            return $pre + 1;
        }

        return self::positionByClock(array_map(fn (PlacedMeal $meal) => $meal->time, $placed), $workout, $window);
    }

    /**
     * The same for meals already on the day: the workout comes before an intra-workout shake, which is kept at its
     * start.
     *
     * @param  list<RetargetMeal>  $meals
     */
    private static function workoutPositionAmong(array $meals, WorkoutTime $workout, ClockRange $window): int
    {
        $shake = array_find_key($meals, fn (RetargetMeal $meal) => $meal->kind === WeekMealKind::IntraWorkoutShake);
        if ($shake !== null) {
            return $shake;
        }
        $post = array_find_key($meals, fn (RetargetMeal $meal) => $meal->kind === WeekMealKind::PostWorkout);
        if ($post !== null) {
            return $post;
        }
        $pre = array_find_key($meals, fn (RetargetMeal $meal) => $meal->kind === WeekMealKind::PreWorkout);
        if ($pre !== null) {
            return $pre + 1;
        }

        return self::positionByClock(array_map(fn (RetargetMeal $meal) => $meal->time, $meals), $workout, $window);
    }

    /** @param  list<ClockTime>  $times */
    private static function positionByClock(array $times, WorkoutTime $workout, ClockRange $window): int
    {
        return array_find_key($times, fn (ClockTime $time) => $window->offset($time) > $window->offset($workout->start)) ?? count($times);
    }

    private static function kindOf(MealRole $role): WeekMealKind
    {
        return match ($role) {
            MealRole::Regular => WeekMealKind::Regular,
            MealRole::PreWorkout => WeekMealKind::PreWorkout,
            MealRole::PostWorkout => WeekMealKind::PostWorkout,
        };
    }

    /** SCH-056: "Meals updated for your 5:30 PM workout". */
    private static function message(ClockTime $start): string
    {
        return "Meals updated for your {$start->shortText()} workout";
    }

    /** @param  list<DateTimeImmutable>  $dates */
    private static function containsDay(array $dates, DateTimeImmutable $day, DateTimeZone $zone): bool
    {
        return array_any($dates, fn (DateTimeImmutable $date) => self::isSameDay($date, $day, $zone));
    }

    private static function isSameDay(DateTimeImmutable $a, DateTimeImmutable $b, DateTimeZone $zone): bool
    {
        return $a->setTimezone($zone)->format('Y-m-d') === $b->setTimezone($zone)->format('Y-m-d');
    }
}
