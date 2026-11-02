<?php

namespace App\Support\Today;

use App\Domain\DayBalance\DayBalance;
use App\Domain\DayBalance\Slot;
use App\Domain\Model\Meal;
use App\Domain\Model\MealTarget;
use App\Domain\Safety\SafetyWarning;
use App\Domain\Safety\SafetyWarningKind;
use App\Domain\Safety\SafetyWarnings;
use DateTimeImmutable;

/**
 * Redistribute (DAY-002, DAY-003) and the day's nutrient warnings (SAFE-018): re-fitting the rest of a day after the
 * user ate off-plan. Ported from rp-clone's TodayModel+Redistribute.
 */
final class Redistribution
{
    /**
     * DAY-002: the meals that can take a share: not checked in, later in the day, and not locked. Today's later meals
     * are the ones after the time now (a meal with no time counts as later), a future day's are all, a past day has
     * none. DAY-003: a meal whose foods are all locked has nothing to re-fit.
     *
     * @param  DateTimeImmutable  $now  the time now, its date and clock read as they are
     * @return list<DayMeal>
     */
    public static function receivable(DayPlan $day, DateTimeImmutable $now): array
    {
        $today = DayPlans::day($now->format('Y-m-d'));
        if ($day->date < $today) {
            return [];
        }
        $minuteNow = (int) $now->format('G') * 60 + (int) $now->format('i');

        return array_values(array_filter($day->meals, function (DayMeal $meal) use ($day, $today, $minuteNow) {
            if ($meal->isChecked() || $meal->onlyLocked) {
                return false;
            }

            return $day->date > $today || $meal->time === null || $meal->time->minuteOfDay > $minuteNow;
        }));
    }

    /**
     * DAY-002: the day's remaining calories and macros spread over the receiving meals. The remaining is the day's
     * target less what was eaten (check-ins and off-plan food) and the targets of the meals that do not receive.
     *
     * @param  list<DayMeal>  $receiving
     * @return array<string, MealTarget> the receiving meals' new targets, by id
     */
    public static function targets(DayPlan $day, array $receiving): array
    {
        $receivingIds = array_map(fn (DayMeal $meal) => $meal->id, $receiving);
        $spent = $day->offPlan;
        foreach ($day->meals as $meal) {
            if ($meal->eaten !== null) {
                $spent[] = $meal->eaten;
            } elseif (! in_array($meal->id, $receivingIds, true)) {
                $spent[] = $meal->target;
            }
        }
        $slots = DayBalance::redistribute(
            DayBalance::remaining($day->target, $spent),
            array_map(fn (DayMeal $meal) => new Slot($meal->id, $meal->target), $receiving),
        );

        $targets = [];
        foreach ($slots as $slot) {
            $targets[$slot->id] = $slot->target;
        }

        return $targets;
    }

    /**
     * DAY-002: the receiving meals the user kept chosen.
     *
     * @param  list<string>  $excluded
     * @return list<DayMeal>
     */
    public static function chosen(DayPlan $day, DateTimeImmutable $now, array $excluded): array
    {
        return array_values(array_filter(self::receivable($day, $now), fn (DayMeal $meal) => ! in_array($meal->id, $excluded, true)));
    }

    /**
     * SAFE-018: the warnings of a day's meals: a checked-in meal counts at what was eaten, any other at its target, or
     * at the projected one. A meal already eaten is not warned about, as there is nothing left to fix in it.
     *
     * @param  array<string, MealTarget>  $projected  by meal id
     * @return list<string>
     */
    public static function warnings(DayPlan $day, array $projected = []): array
    {
        $meals = array_map(
            fn (DayMeal $meal) => new Meal($meal->id, $meal->name, $meal->eaten ?? $projected[$meal->id] ?? $meal->target),
            $day->meals,
        );
        $eaten = array_map(fn (DayMeal $meal) => $meal->name, array_filter($day->meals, fn (DayMeal $meal) => $meal->isChecked()));

        $kept = array_filter(SafetyWarnings::nutrients($meals), fn (SafetyWarning $warning) => ! (
            in_array($warning->kind, [SafetyWarningKind::MealFatBelowMinimum, SafetyWarningKind::MealCarbsBelowMinimum], true)
            && in_array($warning->meal, $eaten, true)
        ));

        return array_values(array_map(fn (SafetyWarning $warning) => $warning->message(), $kept));
    }
}
