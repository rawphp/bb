<?php

namespace App\Domain\DayBalance;

use App\Domain\Macros\MacroRules;
use App\Domain\Model\MealTarget;

/** Day Balance: re-fitting the rest of a day when the user ate off-plan (DAY-002, DAY-020). */
final class DayBalance
{
    /** DAY-021. */
    public const SMALL_MISSES_NOTE = 'Small misses are fine. Within 10% on calories and protein each day is a success.';

    private const TOLERANCE = 1e-9;

    /**
     * DAY-002: what is left of the day's target once everything in `$spent` is taken out: the meals eaten, the food
     * eaten off-plan and the meals that keep their targets. Negative when the day is overspent.
     *
     * @param  list<MealTarget>  $spent
     */
    public static function remaining(MealTarget $day, array $spent): MealTarget
    {
        $sum = fn (string $macro): float => array_sum(array_map(fn (MealTarget $t) => $t->{$macro}, $spent));

        return new MealTarget(
            kcal: $day->kcal - $sum('kcal'),
            proteinG: $day->proteinG - $sum('proteinG'),
            fatG: $day->fatG - $sum('fatG'),
            carbsG: $day->carbsG - $sum('carbsG'),
        );
    }

    /**
     * DAY-002: spreads each macro of `$remaining` across the meals in proportion to the meal's current target for that
     * macro, or equally when none of them has any. Fat and carbs never fall under 5 g a meal (NUT-036, SAFE-010) and
     * protein not under 0, so a day with nothing left still gives each meal its floors. A meal's calories come from
     * MacroRules::kcal.
     *
     * @param  list<Slot>  $among
     * @return list<Slot>
     */
    public static function redistribute(MealTarget $remaining, array $among): array
    {
        if ($among === []) {
            return [];
        }

        $spread = function (float $left, string $macro) use ($among): array {
            $weights = array_map(fn (Slot $s) => $s->target->{$macro}, $among);
            $total = array_sum($weights);

            return $total > 0
                ? array_map(fn (float $w) => $left * $w / $total, $weights)
                : array_fill(0, count($among), $left / count($among));
        };
        $protein = $spread($remaining->proteinG, 'proteinG');
        $fat = $spread($remaining->fatG, 'fatG');
        $carbs = $spread($remaining->carbsG, 'carbsG');

        return array_map(function (int $index) use ($among, $protein, $fat, $carbs): Slot {
            $proteinG = max(0.0, $protein[$index]);
            $fatG = max(MacroRules::MEAL_MINIMUM_G, $fat[$index]);
            $carbsG = max(MacroRules::MEAL_MINIMUM_G, $carbs[$index]);

            return new Slot($among[$index]->id, new MealTarget(
                kcal: MacroRules::kcal(proteinG: $proteinG, carbsG: $carbsG, fatG: $fatG),
                proteinG: $proteinG,
                fatG: $fatG,
                carbsG: $carbsG,
            ));
        }, array_keys($among));
    }

    /**
     * DAY-020: changes the carbs of a day's meals by `$by` grams in all, spread in proportion to the meals' carbs, or
     * equally when they have none. A meal's calories follow its carbs, and its protein and fat hold (NUT-022). A meal
     * that would keep under 5 g of carbs refuses the change (SAFE-010).
     *
     * @param  list<Slot>  $over
     * @return list<Slot>
     *
     * @throws MealMoveRefusal
     */
    public static function shiftingCarbs(float $by, array $over): array
    {
        if ($over === []) {
            throw MealMoveRefusal::noMeals();
        }

        $total = array_sum(array_map(fn (Slot $s) => $s->target->carbsG, $over));

        return array_map(function (Slot $slot) use ($by, $total, $over): Slot {
            $share = $total > 0 ? $slot->target->carbsG / $total : 1 / count($over);
            $change = $by * $share;
            $carbsG = $slot->target->carbsG + $change;
            if ($carbsG < MacroRules::MEAL_MINIMUM_G - self::TOLERANCE) {
                throw MealMoveRefusal::belowCarbMinimum($slot->id);
            }

            return new Slot($slot->id, new MealTarget(
                kcal: $slot->target->kcal + MacroRules::KCAL_PER_GRAM_CARBS * $change,
                proteinG: $slot->target->proteinG,
                fatG: $slot->target->fatG,
                carbsG: $carbsG,
            ));
        }, $over);
    }

    /**
     * DAY-020: moves `$kcal` from one meal to another through carbs (4 kcal a gram). Each meal keeps 5 g of carbs.
     *
     * @throws MealMoveRefusal
     */
    public static function moving(float $kcal, Slot $from, Slot $to): MealMove
    {
        if (! is_finite($kcal) || $kcal <= 0 || $from->id === $to->id) {
            throw MealMoveRefusal::invalid();
        }

        $grams = $kcal / MacroRules::KCAL_PER_GRAM_CARBS;

        return new MealMove(
            from: self::shiftingCarbs(-$grams, [$from])[0],
            to: self::shiftingCarbs($grams, [$to])[0],
        );
    }
}
