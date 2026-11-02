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
        /** @param callable(MealTarget): float $macro */
        $sum = fn (callable $macro): float => array_sum(array_map($macro, $spent));

        return new MealTarget(
            kcal: $day->kcal - $sum(fn (MealTarget $t) => $t->kcal),
            proteinG: $day->proteinG - $sum(fn (MealTarget $t) => $t->proteinG),
            fatG: $day->fatG - $sum(fn (MealTarget $t) => $t->fatG),
            carbsG: $day->carbsG - $sum(fn (MealTarget $t) => $t->carbsG),
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

        /**
         * @param  callable(MealTarget): float  $macro
         * @return list<float>
         */
        $spread = function (float $left, callable $macro) use ($among): array {
            $weights = array_map(fn (Slot $s) => $macro($s->target), $among);
            $total = array_sum($weights);

            return $total > 0
                ? array_map(fn (float $w) => $left * $w / $total, $weights)
                : array_fill(0, count($among), $left / count($among));
        };
        $protein = $spread($remaining->proteinG, fn (MealTarget $t) => $t->proteinG);
        $fat = $spread($remaining->fatG, fn (MealTarget $t) => $t->fatG);
        $carbs = $spread($remaining->carbsG, fn (MealTarget $t) => $t->carbsG);

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
     * that would lose carbs and keep under 5 g refuses the change (SAFE-010); one that lands within float rounding of
     * 5 g holds exactly 5 g. A meal that gains carbs, or keeps what
     * it has, never refuses, even when it was under 5 g already.
     *
     * @param  list<Slot>  $over
     * @return list<Slot>
     *
     * @throws MealMoveRefusal
     */
    public static function shiftingCarbs(float $by, array $over): array
    {
        if (! is_finite($by)) {
            throw MealMoveRefusal::invalid();
        }
        if ($over === []) {
            throw MealMoveRefusal::noMeals();
        }

        $total = array_sum(array_map(fn (Slot $s) => $s->target->carbsG, $over));

        return array_map(function (Slot $slot) use ($by, $total, $over): Slot {
            $share = $total > 0 ? $slot->target->carbsG / $total : 1 / count($over);
            $change = $by * $share;
            $carbsG = $slot->target->carbsG + $change;
            if ($change < 0 && $carbsG < MacroRules::MEAL_MINIMUM_G) {
                if ($carbsG < MacroRules::MEAL_MINIMUM_G - self::TOLERANCE) {
                    throw MealMoveRefusal::belowCarbMinimum($slot->id);
                }
                // Float rounding only: hold exactly the floor, so SafetyWarnings' plain `< 5` check agrees.
                $carbsG = MacroRules::MEAL_MINIMUM_G;
                $change = $carbsG - $slot->target->carbsG;
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
     * DAY-020: moves `$kcal` from one meal to another through carbs (4 kcal a gram). The giving meal keeps 5 g of
     * carbs; the receiving meal only gains, so it never refuses (SAFE-010).
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
