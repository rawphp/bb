<?php

namespace App\Support\Today;

use App\Domain\DayBalance\DayBalance;
use App\Domain\DayBalance\MealMoveRefusal;
use App\Domain\DayBalance\MealMoveRefusalReason;
use App\Domain\DayBalance\Slot;
use App\Domain\Macros\DayTarget;
use App\Domain\Macros\MacroRules;
use App\Domain\Macros\MoveRefusal;
use App\Domain\Macros\MoveRefusalReason;
use App\Domain\Macros\WeekTargets;
use App\Domain\Model\MealTarget;
use DateTimeImmutable;

/**
 * The Adjust sheet (DAY-020): moves calories between a day's meals, or between the days of its plan week, within the
 * limits of NUT-021 to NUT-024. Calories always move through carbs, so protein and fat hold (NUT-022). Ported from
 * rp-clone's TodayModel+Adjust.
 */
final class Adjustment
{
    /** Without a weight on record the move rules read 70 kg, as rp-clone does. */
    public const FALLBACK_WEIGHT_KG = 70.0;

    /**
     * @param  list<DayPlan>  $week  the stored days of the selected day's plan week, in order, the selected day among them
     * @param  'meals'|'days'|string  $mode
     * @param  'give'|'take'|string  $direction  between days: the selected day gives to the other day or takes from it
     */
    public static function outcome(
        DayPlan $selected,
        array $week,
        string $mode,
        ?int $kcal,
        ?string $fromMealId,
        ?string $toMealId,
        ?string $otherDate,
        string $direction,
        DateTimeImmutable $today,
        ?float $weightKg,
    ): AdjustOutcome {
        if ($kcal === null || $kcal <= 0) {
            return AdjustOutcome::incomplete('Enter the calories to move.');
        }

        return $mode === 'days'
            ? self::dayMove($kcal, $selected, $week, $otherDate, $direction, $today, $weightKg ?? self::FALLBACK_WEIGHT_KG)
            : self::mealMove($kcal, $selected, $fromMealId === null ? null : strtolower($fromMealId), $toMealId === null ? null : strtolower($toMealId));
    }

    /**
     * @param  list<DayPlan>  $week
     * @return list<DayPlan> NUT-021: the other days of the plan week
     */
    public static function otherDays(DayPlan $selected, array $week): array
    {
        return array_values(array_filter($week, fn (DayPlan $day) => $day->date != $selected->date));
    }

    private static function mealMove(int $kcal, DayPlan $day, ?string $fromId, ?string $toId): AdjustOutcome
    {
        $open = array_map(fn (DayMeal $meal) => $meal->id, $day->openMeals());
        if ($fromId === null || $toId === null || $fromId === $toId || ! in_array($fromId, $open, true) || ! in_array($toId, $open, true)) {
            return AdjustOutcome::incomplete('Choose two different meals that are not checked in.');
        }
        $from = $day->meal($fromId);
        $to = $day->meal($toId);
        try {
            $moved = DayBalance::moving($kcal, new Slot($from->id, $from->target), new Slot($to->id, $to->target));
        } catch (MealMoveRefusal $refusal) {
            return self::carbRefusal($refusal, $day);
        }

        return AdjustOutcome::plan([self::changed($day, $day->target, [$moved->from, $moved->to])]);
    }

    /** @param  list<DayPlan>  $week */
    private static function dayMove(
        int $kcal, DayPlan $selected, array $week, ?string $otherDate, string $direction, DateTimeImmutable $today, float $weightKg,
    ): AdjustOutcome {
        $selectedIndex = array_find_key($week, fn (DayPlan $day) => $day->date == $selected->date);
        $otherIndex = $otherDate === null ? null : array_find_key($week, fn (DayPlan $day) => $day->date->format('Y-m-d') === $otherDate);
        if ($selectedIndex === null || $otherIndex === null || $otherIndex === $selectedIndex) {
            return AdjustOutcome::incomplete('Choose another day of this plan week.');
        }

        // NUT-021 to NUT-024: the week as the move rules see it, protein and fat as stored.
        $targets = new WeekTargets(
            days: array_map(fn (DayPlan $day) => new DayTarget(
                $day->type, $day->isFinished($today), $day->target->proteinG, $day->target->fatG, $day->target->carbsG, null,
            ), $week),
            leanMassKg: $weightKg,
            mealsPerDay: max(array_map(fn (DayPlan $day) => count($day->meals), $week)),
        );
        [$from, $to] = $direction === 'take' ? [$otherIndex, $selectedIndex] : [$selectedIndex, $otherIndex];
        try {
            $moved = $targets->moving($kcal, $from, $to);
        } catch (MoveRefusal $refusal) {
            return self::moveRefusal($refusal, $week);
        }

        $changed = [];
        foreach ([$selectedIndex, $otherIndex] as $index) {
            $before = $week[$index];
            $carbsDelta = $moved->days[$index]->carbsG - $before->target->carbsG;
            try {
                $slots = DayBalance::shiftingCarbs(
                    $carbsDelta,
                    array_map(fn (DayMeal $meal) => new Slot($meal->id, $meal->target), $before->openMeals()),
                );
            } catch (MealMoveRefusal $refusal) {
                return self::carbRefusal($refusal, $before);
            }
            $changed[] = self::changed($before, new MealTarget(
                kcal: $before->target->kcal + MacroRules::KCAL_PER_GRAM_CARBS * $carbsDelta,
                proteinG: $before->target->proteinG,
                fatG: $before->target->fatG,
                carbsG: $moved->days[$index]->carbsG,
            ), $slots);
        }

        return AdjustOutcome::plan($changed);
    }

    /**
     * @param  list<Slot>  $slots
     * @return array{date: DateTimeImmutable, target: MealTarget, meals: array<string, MealTarget>}
     */
    private static function changed(DayPlan $day, MealTarget $target, array $slots): array
    {
        $meals = [];
        foreach ($day->meals as $meal) {
            $meals[$meal->id] = $meal->target;
        }
        foreach ($slots as $slot) {
            $meals[$slot->id] = $slot->target;
        }

        return ['date' => $day->date, 'target' => $target, 'meals' => $meals];
    }

    private static function carbRefusal(MealMoveRefusal $refusal, DayPlan $day): AdjustOutcome
    {
        return match ($refusal->reason) {
            MealMoveRefusalReason::Invalid => AdjustOutcome::incomplete('Enter the calories to move.'),
            MealMoveRefusalReason::NoMeals => AdjustOutcome::refused($day->date->format('l').' has no meal to take the change.'),
            MealMoveRefusalReason::BelowCarbMinimum => AdjustOutcome::refused(($day->meal((string) $refusal->id)->name ?? 'A meal').' would keep less than 5 g of carbs.'),
        };
    }

    /** @param  list<DayPlan>  $week */
    private static function moveRefusal(MoveRefusal $refusal, array $week): AdjustOutcome
    {
        $name = isset($week[$refusal->dayIndex]) ? $week[$refusal->dayIndex]->date->format('l') : 'That day';

        return match ($refusal->reason) {
            MoveRefusalReason::DayCompleted => AdjustOutcome::refused("{$name} is finished, so its calories can't move."),
            MoveRefusalReason::OutsideBand => AdjustOutcome::refused("That would take {$name} outside 80% to 125% of the week's daily average."),
            MoveRefusalReason::BreaksFloor => AdjustOutcome::refused("That would take {$name} under its fat or carb minimum."),
            MoveRefusalReason::Invalid => AdjustOutcome::incomplete('Enter the calories to move.'),
        };
    }
}
