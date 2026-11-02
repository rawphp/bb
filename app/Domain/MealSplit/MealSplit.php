<?php

namespace App\Domain\MealSplit;

use App\Domain\Defaults\TBL033CarbsPerMeal;
use App\Domain\Macros\MacroRules;
use App\Domain\Model\MealTarget;
use LogicException;

/**
 * NUT-030 to NUT-037: splits a day's targets into per-meal targets around the workouts.
 *
 * Slots are the meals and shakes in day order; workouts only mark positions. Weights and results are lists
 * indexed by slot.
 */
final class MealSplit
{
    /**
     * NUT-032: fat weight of the pre- and post-workout meals against 1.0 for the rest.
     * The spec only says "lowest"; 0.5 is a guess it leaves open.
     */
    public const PERI_WORKOUT_FAT_WEIGHT = 0.5;

    /** NUT-033: protein weight of the bedtime (last) meal: up to 15% more. */
    public const BEDTIME_PROTEIN_WEIGHT = 1.15;

    /** NUT-034: rounding step for grams and kcal. */
    public const ROUNDING_STEP = 5.0;

    /** NUT-037: most points that move from the pre-workout share to bedtime. */
    public const EVENING_SHIFT_POINTS = 10.0;

    /**
     * @param  list<DayEntry>  $entries
     * @return list<MealSplitItem> one per meal and shake, in day order
     */
    public static function split(MealTarget $day, array $entries, CarbPlacement $placement = CarbPlacement::TrainingCentred): array
    {
        /** @var list<DayEntry> $slots */
        $slots = [];
        /** @var list<int> $entryIndexes */
        $entryIndexes = [];
        $workoutIndexes = [];
        foreach ($entries as $index => $entry) {
            if ($entry->kind === DayEntryKind::Workout) {
                $workoutIndexes[] = $index;
            } else {
                $slots[] = $entry;
                $entryIndexes[] = $index;
            }
        }
        $mealSlots = array_keys(array_filter($slots, fn (DayEntry $slot) => ! self::isShake($slot)));
        if ($mealSlots === []) {
            return [];
        }

        $isTrainingDay = $workoutIndexes !== [];
        $bed = $mealSlots[count($mealSlots) - 1];
        $pre = null;
        $post = null;
        if ($isTrainingDay) {
            $firstWorkout = $workoutIndexes[0];
            $lastWorkout = $workoutIndexes[count($workoutIndexes) - 1];
            foreach ($mealSlots as $i) {
                if ($entryIndexes[$i] < $firstWorkout) {
                    $pre = $i;
                }
                if ($post === null && $entryIndexes[$i] > $lastWorkout) {
                    $post = $i;
                }
            }
        }

        $carbWeights = self::carbWeights($slots, $mealSlots, $pre, $post, $bed, $isTrainingDay, $placement);
        $fatWeights = self::fatWeights($slots, $mealSlots, $pre, $post, $isTrainingDay);
        $proteinWeights = self::proteinWeights($slots, $mealSlots, $isTrainingDay);

        $protein = self::distribute($day->proteinG, $slots, $proteinWeights, fn (MealTarget $t) => $t->proteinG, hasFloor: false);
        $fat = self::distribute($day->fatG, $slots, $fatWeights, fn (MealTarget $t) => $t->fatG, hasFloor: true);
        $carbs = self::distribute($day->carbsG, $slots, $carbWeights, fn (MealTarget $t) => $t->carbsG, hasFloor: true);
        $kcal = self::distributeEnergy($day, $slots, $protein, $fat, $carbs);

        $items = [];
        foreach ($slots as $i => $slot) {
            $items[] = new MealSplitItem(
                $slot->id ?? throw new LogicException('Meals and shakes always have an id.'),
                $slot->fixed ?? new MealTarget(kcal: $kcal[$i], proteinG: $protein[$i], fatG: $fat[$i], carbsG: $carbs[$i]),
            );
        }

        return $items;
    }

    private static function isShake(DayEntry $slot): bool
    {
        return $slot->kind === DayEntryKind::IntraWorkoutShake;
    }

    /**
     * NUT-031, NUT-037, TBL-033: weights are percentage points of the day's carbs.
     *
     * @param  list<DayEntry>  $slots
     * @param  list<int>  $mealSlots
     * @return list<float>
     */
    private static function carbWeights(
        array $slots, array $mealSlots, ?int $preSlot, ?int $postSlot, int $bedSlot,
        bool $isTrainingDay, CarbPlacement $placement,
    ): array {
        $weights = array_fill(0, count($slots), 0.0);
        $n = count($mealSlots);
        $shakeCount = count(array_filter($slots, fn (DayEntry $slot) => self::isShake($slot)));
        $shakePoints = $isTrainingDay && $shakeCount > 0 ? TBL033CarbsPerMeal::INTRA_WORKOUT_SHAKE_TAKES_POINTS : 0.0;
        $setShake = function () use (&$weights, $slots, $shakePoints) {
            foreach ($slots as $i => $slot) {
                if (self::isShake($slot)) {
                    $weights[$i] = $shakePoints;
                }
            }
        };

        if (! $isTrainingDay || $n < 2) {
            $meals = max(0.0, 100 - $shakePoints * $shakeCount);
            foreach ($mealSlots as $i) {
                $weights[$i] = $meals / $n;
            }
            $setShake();

            return $weights;
        }

        $row = TBL033CarbsPerMeal::row(min($n, 6));
        assert($row !== null);
        $pre = $row->preWorkoutPercent;
        $post = $row->postWorkoutPercent;
        $bed = $row->bedtimePercent ?? 0.0;
        $rest = $row->restPercent ?? 0.0;

        // Every shake takes its points from the post-workout share, which cannot go below 0. Points beyond that
        // share push the weights past 100, and the split's normalising takes them from every meal (as in rp-clone).
        $post = max(0.0, $post - $shakePoints * $shakeCount);
        if ($placement === CarbPlacement::EveningWeighted) {
            $moved = min(self::EVENING_SHIFT_POINTS, $pre);
            $pre -= $moved;
            $bed += $moved;
        }
        // A workout before the first meal or after the last leaves one side empty; its share goes to the other
        // side. With neither (meals only between workouts) both shares fall into the rest.
        if ($preSlot === null && $postSlot === null) {
            $rest += $pre + $post;
            $pre = 0.0;
            $post = 0.0;
        } elseif ($preSlot === null) {
            $post += $pre;
            $pre = 0.0;
        } elseif ($postSlot === null) {
            $pre += $post;
            $post = 0.0;
        }

        if ($preSlot !== null) {
            $weights[$preSlot] += $pre;
        }
        if ($postSlot !== null) {
            $weights[$postSlot] += $post;
        }
        $weights[$bedSlot] += $bed;

        $unnamed = array_values(array_filter($mealSlots, fn (int $i) => $i !== $preSlot && $i !== $postSlot && $i !== $bedSlot));
        $restTargets = $unnamed === [] ? $mealSlots : $unnamed;
        foreach ($restTargets as $i) {
            $weights[$i] += $rest / count($restTargets);
        }
        $setShake();

        return $weights;
    }

    /**
     * NUT-032: pre- and post-workout meals carry the least fat.
     *
     * @param  list<DayEntry>  $slots
     * @param  list<int>  $mealSlots
     * @return list<float>
     */
    private static function fatWeights(array $slots, array $mealSlots, ?int $pre, ?int $post, bool $isTrainingDay): array
    {
        $weights = array_fill(0, count($slots), 0.0);
        $peri = array_values(array_filter([$pre, $post], fn (?int $i) => $i !== null));
        $hasDistantMeal = array_filter($mealSlots, fn (int $i) => ! in_array($i, $peri, true)) !== [];
        foreach ($mealSlots as $i) {
            $weights[$i] = $isTrainingDay && $hasDistantMeal && in_array($i, $peri, true) ? self::PERI_WORKOUT_FAT_WEIGHT : 1.0;
        }

        return $weights;
    }

    /**
     * NUT-033: even, with the bedtime meal getting a little more. A shake only carries protein around a workout.
     *
     * @param  list<DayEntry>  $slots
     * @param  list<int>  $mealSlots
     * @return list<float>
     */
    private static function proteinWeights(array $slots, array $mealSlots, bool $isTrainingDay): array
    {
        $weights = array_fill(0, count($slots), 1.0);
        if (! $isTrainingDay) {
            foreach ($slots as $i => $slot) {
                if (self::isShake($slot)) {
                    $weights[$i] = 0.0;
                }
            }
        }
        if (count($mealSlots) >= 2) {
            $weights[$mealSlots[count($mealSlots) - 1]] = self::BEDTIME_PROTEIN_WEIGHT;
        }

        return $weights;
    }

    /**
     * Splits one macro over the unfixed items, applies the SAFE-010 floor when $hasFloor, and rounds (NUT-034).
     * Fixed items keep their amount and take their share off the day.
     *
     * @param  list<DayEntry>  $slots
     * @param  list<float>  $weights
     * @param  callable(MealTarget): float  $field
     * @return list<float>
     */
    private static function distribute(float $total, array $slots, array $weights, callable $field, bool $hasFloor): array
    {
        $result = array_fill(0, count($slots), 0.0);
        $free = array_keys(array_filter($slots, fn (DayEntry $slot) => $slot->fixed === null));
        if ($free === []) {
            return $result;
        }

        $remaining = self::remaining($total, $slots, $field);

        if ($hasFloor && self::floorsWin($total, $slots, $field)) {
            // NUT-036, SCH-053: the floors win over the day total.
            foreach (self::freeMeals($slots) as $i) {
                $result[$i] = MacroRules::MEAL_MINIMUM_G;
            }

            return $result;
        }

        $raw = $hasFloor
            ? self::waterFill($remaining, $free, $weights, $slots)
            : self::proportional($remaining, $free, $weights, $slots);
        foreach ($free as $i) {
            $result[$i] = $raw[$i] ?? 0.0;
        }

        // When nothing could take the amount (only a weightless shake is free), the remainder stays unallocated,
        // so round against what was actually handed out.
        $allocated = 0.0;
        foreach ($free as $i) {
            $allocated += $result[$i];
        }
        $target = abs($allocated - $remaining) < 1e-9 ? $remaining : $allocated;
        $rounded = self::roundHamilton(array_map(fn (int $i) => $result[$i], $free), $target);
        foreach ($free as $k => $i) {
            $result[$i] = $rounded[$k];
        }

        return $result;
    }

    /**
     * With no weight to go by, the amount splits evenly over the meals; shakes get nothing. With no meals either,
     * nothing is allocated.
     *
     * @param  list<int>  $indexes
     * @param  list<float>  $weights
     * @param  list<DayEntry>  $slots
     * @return array<int, float>
     */
    private static function proportional(float $amount, array $indexes, array $weights, array $slots): array
    {
        $sum = 0.0;
        foreach ($indexes as $i) {
            $sum += $weights[$i];
        }
        $meals = count(array_filter($indexes, fn (int $i) => ! self::isShake($slots[$i])));
        $out = [];
        foreach ($indexes as $i) {
            if ($sum > 0) {
                $out[$i] = $amount * $weights[$i] / $sum;
            } else {
                $out[$i] = ! self::isShake($slots[$i]) ? $amount / $meals : 0.0;
            }
        }

        return $out;
    }

    /**
     * Splits by weight, raising any meal under the floor to it and re-splitting the rest.
     *
     * @param  list<int>  $free
     * @param  list<float>  $weights
     * @param  list<DayEntry>  $slots
     * @return array<int, float>
     */
    private static function waterFill(float $remaining, array $free, array $weights, array $slots): array
    {
        /** @var array<int, true> $clamped */
        $clamped = [];
        while (true) {
            $open = array_values(array_filter($free, fn (int $i) => ! isset($clamped[$i])));
            $left = $remaining - MacroRules::MEAL_MINIMUM_G * count($clamped);
            $split = self::proportional($left, $open, $weights, $slots);
            $under = array_filter($open, fn (int $i) => ! self::isShake($slots[$i]) && ($split[$i] ?? 0.0) < MacroRules::MEAL_MINIMUM_G);
            if ($under === [] || $open === []) {
                foreach (array_keys($clamped) as $i) {
                    $split[$i] = MacroRules::MEAL_MINIMUM_G;
                }

                return $split;
            }
            foreach ($under as $i) {
                $clamped[$i] = true;
            }
        }
    }

    /**
     * What the day has left of one macro once the fixed items take theirs, never below 0.
     *
     * @param  list<DayEntry>  $slots
     * @param  callable(MealTarget): float  $field
     */
    private static function remaining(float $total, array $slots, callable $field): float
    {
        $fixedSum = 0.0;
        foreach ($slots as $slot) {
            if ($slot->fixed !== null) {
                $fixedSum += $field($slot->fixed);
            }
        }

        return max(0.0, $total - $fixedSum);
    }

    /**
     * Unfixed meals, not shakes: the slots the SAFE-010 floor applies to.
     *
     * @param  list<DayEntry>  $slots
     * @return list<int>
     */
    private static function freeMeals(array $slots): array
    {
        return array_keys(array_filter($slots, fn (DayEntry $slot) => $slot->fixed === null && ! self::isShake($slot)));
    }

    /**
     * NUT-036, SCH-053: what the day has left of a floored macro cannot give every free meal its 5 g, so the free
     * meals get 5 g each and together hold more than the day.
     *
     * @param  list<DayEntry>  $slots
     * @param  callable(MealTarget): float  $field
     */
    private static function floorsWin(float $total, array $slots, callable $field): bool
    {
        return self::remaining($total, $slots, $field) < MacroRules::MEAL_MINIMUM_G * count(self::freeMeals($slots));
    }

    /**
     * kcal follows the rounded grams (MacroRules::kcal), scaled so the meals add up to the day (NUT-030).
     *
     * The day's own kcal can differ from the kcal of its grams, so the scale is usually near the day's ratio
     * (day kcal / kcal of the day's grams). It never goes below that ratio. When the fixed meals took more kcal than
     * their food carries, or the SAFE-010 floors put more food in the free meals than the day holds (NUT-036), the
     * kcal left cannot cover the free meals' food; scaling to it would show, say, 205 kcal of food as 1 kcal. The
     * free meals then take their food's kcal at the day's ratio, rounded to 5, and the day is overspent, as its grams
     * already are. rp-clone scales to whatever kcal is left; this departs from it on purpose and leaves every
     * rp-clone test value unchanged.
     *
     * @param  list<DayEntry>  $slots
     * @param  list<float>  $protein
     * @param  list<float>  $fat
     * @param  list<float>  $carbs
     * @return list<float>
     */
    private static function distributeEnergy(MealTarget $day, array $slots, array $protein, array $fat, array $carbs): array
    {
        $result = array_fill(0, count($slots), 0.0);
        $free = array_keys(array_filter($slots, fn (DayEntry $slot) => $slot->fixed === null));
        if ($free === []) {
            return $result;
        }

        $energy = array_map(fn (int $i) => MacroRules::kcal(proteinG: $protein[$i], carbsG: $carbs[$i], fatG: $fat[$i]), $free);
        $energySum = array_sum($energy);
        $fixedKcal = 0.0;
        foreach ($slots as $slot) {
            $fixedKcal += $slot->fixed !== null ? $slot->fixed->kcal : 0.0;
        }
        $remainingKcal = $day->kcal - $fixedKcal;
        $dayFoodKcal = MacroRules::kcal(proteinG: $day->proteinG, carbsG: $day->carbsG, fatG: $day->fatG);
        $dayRatio = $dayFoodKcal > 0 ? $day->kcal / $dayFoodKcal : 1.0;

        if ($energySum > 0 && $remainingKcal + 1e-6 >= $dayRatio * $energySum) {
            $values = array_map(fn (float $e) => $remainingKcal * $e / $energySum, $energy);
            $total = $remainingKcal;
        } else {
            $values = array_map(fn (float $e) => $dayRatio * $e, $energy);
            $total = round(array_sum($values) / self::ROUNDING_STEP) * self::ROUNDING_STEP;
        }
        $rounded = self::roundHamilton($values, $total);
        foreach ($free as $k => $i) {
            $result[$i] = $rounded[$k];
        }

        return $result;
    }

    /**
     * NUT-034: rounds to 5-unit steps so the values still add up to $total. Whole 5s go to the largest fractions
     * (earlier item on a tie), and what is left over (under 5, possibly fractional) goes to the largest item.
     *
     * @param  list<float>  $values
     * @return list<float>
     */
    private static function roundHamilton(array $values, float $total): array
    {
        if ($values === []) {
            return [];
        }
        $step = self::ROUNDING_STEP;
        $out = array_map(fn (float $v) => floor($v / $step) * $step, $values);
        $leftover = $total - array_sum($out);
        $chunks = min(count($values), max(0, (int) (($leftover + 1e-9) / $step)));

        $byFraction = array_keys($values);
        usort($byFraction, function (int $a, int $b) use ($values, $out) {
            $fa = $values[$a] - $out[$a];
            $fb = $values[$b] - $out[$b];

            return $fa != $fb ? $fb <=> $fa : $a <=> $b;
        });
        foreach (array_slice($byFraction, 0, $chunks) as $i) {
            $out[$i] += $step;
        }

        $residual = $leftover - $chunks * $step;
        if ($residual != 0) {
            $largest = 0;
            foreach ($values as $i => $v) {
                if ($v > $values[$largest]) {
                    $largest = $i;
                }
            }
            $out[$largest] += $residual;
        }

        return $out;
    }
}
