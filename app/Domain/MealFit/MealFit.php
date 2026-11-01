<?php

namespace App\Domain\MealFit;

use App\Domain\Model\MealFitRules;
use App\Domain\Model\MealTarget;

/** MEAL-002 to MEAL-007: scales the grams of the foods a user picked so the meal hits its targets. */
final readonly class MealFit implements MealFitRules
{
    /** MEAL-003: "Targets met" when every macro is within this many grams of target. */
    public const TOLERANCE = 5.0;

    /**
     * Ridge weight on the fit, in 100 g units. Small enough to leave the fit alone,
     * large enough that redundant foods share the load and the system stays solvable.
     */
    private const RIDGE = 0.01;

    private const EPSILON = 1e-9;

    /**
     * MEAL-002, MEAL-006: fits the unlocked foods to the target. Locked foods keep their
     * exact grams (MEAL-005). Never adds or removes a food (MEAL-007).
     *
     * @param  list<MealFood>  $foods
     */
    public function fit(array $foods, MealTarget $target): MealFitResult
    {
        return $this->solve($foods, $this->lockedIds($foods), $target);
    }

    /**
     * MEAL-004: the edited food takes `grams` (to 1 g) and stays put while the other unlocked
     * foods rebalance. Its lock flag is untouched. An unknown id is a plain fit.
     *
     * @param  list<MealFood>  $foods
     */
    public function setAmount(float $grams, string $id, array $foods, MealTarget $target): MealFitResult
    {
        $held = $this->lockedIds($foods);

        foreach ($foods as $i => $food) {
            if ($food->id === $id) {
                $foods[$i] = $food->withGrams(round(max(0.0, $grams)));
                $held[] = $id;
                break;
            }
        }

        return $this->solve($foods, $held, $target);
    }

    /** MEAL-003: every one of protein, fat and carbs is within TOLERANCE of target. */
    public function targetsMet(MealTarget $total, MealTarget $target): bool
    {
        return $this->within($total->proteinG, $target->proteinG)
            && $this->within($total->fatG, $target->fatG)
            && $this->within($total->carbsG, $target->carbsG);
    }

    /** @param  list<MealFood>  $foods */
    public function totals(array $foods): MealTarget
    {
        $kcal = $protein = $fat = $carbs = 0.0;

        foreach ($foods as $food) {
            $m = $food->macros();
            $kcal += $m->kcal;
            $protein += $m->proteinG;
            $fat += $m->fatG;
            $carbs += $m->carbsG;
        }

        return new MealTarget(kcal: $kcal, proteinG: $protein, fatG: $fat, carbsG: $carbs);
    }

    private function within(float $total, float $target): bool
    {
        return abs($total - $target) <= self::TOLERANCE + self::EPSILON;
    }

    /**
     * @param  list<MealFood>  $foods
     * @return list<string>
     */
    private function lockedIds(array $foods): array
    {
        return array_values(array_map(
            fn (MealFood $f) => $f->id,
            array_filter($foods, fn (MealFood $f) => $f->isLocked),
        ));
    }

    /**
     * @param  list<MealFood>  $foods
     * @param  list<string>  $held
     */
    private function solve(array $foods, array $held, MealTarget $target): MealFitResult
    {
        $isHeld = fn (MealFood $f) => in_array($f->id, $held, true);
        $free = array_keys(array_filter($foods, fn (MealFood $f) => ! $isHeld($f)));

        if ($free !== []) {
            $fixed = $this->totals(array_values(array_filter($foods, $isHeld)));
            $b = [$target->proteinG - $fixed->proteinG, $target->fatG - $fixed->fatG, $target->carbsG - $fixed->carbsG];
            $columns = array_map(fn (int $i) => [$foods[$i]->food->proteinG, $foods[$i]->food->fatG, $foods[$i]->food->carbsG], $free);
            $units = $this->nonNegativeLeastSquares($columns, $b);

            foreach ($free as $k => $i) {
                $food = $foods[$i];
                $raw = max(0.0, $units[$k]) * 100;
                $foods[$i] = $food->withGrams(round(MealFood::gramsForRaw($raw, $food->food, $food->weighing)));
            }
        }

        return $this->result($foods, $target);
    }

    /** @param  list<MealFood>  $foods */
    private function result(array $foods, MealTarget $target): MealFitResult
    {
        $total = $this->totals($foods);
        $wanted = [
            [Macro::Protein, $target->proteinG, $total->proteinG, CuratedGroup::ProteinRecommended],
            [Macro::Fat, $target->fatG, $total->fatG, CuratedGroup::FatRecommended],
            [Macro::Carbs, $target->carbsG, $total->carbsG, CuratedGroup::CarbsRecommended],
        ];
        $shortfalls = [];

        foreach ($wanted as [$macro, $goal, $have, $group]) {
            if ($goal - $have > self::TOLERANCE + self::EPSILON) {
                $shortfalls[] = new Shortfall($macro, $goal - $have, $group);
            }
        }

        return new MealFitResult($foods, $total, $this->targetsMet($total, $target), $shortfalls);
    }

    /**
     * Minimises |A x - b|^2 + ridge |x|^2 over x >= 0 (Lawson-Hanson active set on the normal
     * equations). `$columns[$j]` is column j of A. Small and unoptimised: a meal has a few foods.
     *
     * @param  list<list<float>>  $columns
     * @param  list<float>  $b
     * @return list<float>
     */
    private function nonNegativeLeastSquares(array $columns, array $b): array
    {
        $n = count($columns);
        $q = [];
        $c = [];

        foreach ($columns as $j => $cj) {
            foreach ($columns as $k => $ck) {
                $q[$j][$k] = $this->dot($cj, $ck);
            }
            $q[$j][$j] += self::RIDGE;
            $c[$j] = $this->dot($cj, $b);
        }

        $x = array_fill(0, $n, 0.0);
        $passive = [];
        $limit = 10 * $n + 10;

        for ($outer = 0; $outer < $limit; $outer++) {
            // Gradient of the unmet part: the free variable that would help most.
            $enter = null;
            for ($j = 0; $j < $n; $j++) {
                $w = $c[$j];
                for ($k = 0; $k < $n; $k++) {
                    $w -= $q[$j][$k] * $x[$k];
                }
                if (! in_array($j, $passive, true) && $w > self::EPSILON && ($enter === null || $w > $enter[1])) {
                    $enter = [$j, $w];
                }
            }
            if ($enter === null) {
                break;
            }
            $passive[] = $enter[0];

            for ($inner = 0; $inner < $limit; $inner++) {
                $s = $this->solveSystem($q, $c, $passive);
                if (min($s) > self::EPSILON) {
                    foreach ($passive as $k => $j) {
                        $x[$j] = $s[$k];
                    }
                    break;
                }

                // Step toward s until the first variable hits zero, then drop it.
                $alpha = 1.0;
                foreach ($passive as $k => $j) {
                    // A zero step is Swift's inf or NaN, which its min() passes over.
                    $step = $x[$j] - $s[$k];
                    if ($s[$k] <= self::EPSILON && $step != 0.0) {
                        $alpha = min($alpha, $x[$j] / $step);
                    }
                }
                foreach ($passive as $k => $j) {
                    $x[$j] += $alpha * ($s[$k] - $x[$j]);
                }
                $passive = array_values(array_filter($passive, fn (int $j) => $x[$j] > self::EPSILON));
                for ($j = 0; $j < $n; $j++) {
                    if (! in_array($j, $passive, true)) {
                        $x[$j] = 0.0;
                    }
                }
                if ($passive === []) {
                    break;
                }
            }
        }

        return $x;
    }

    /**
     * Solves Q[idx, idx] s = c[idx] by Gaussian elimination with partial pivoting.
     *
     * @param  array<int, array<int, float>>  $q
     * @param  array<int, float>  $c
     * @param  list<int>  $indexes
     * @return list<float>
     */
    private function solveSystem(array $q, array $c, array $indexes): array
    {
        $m = count($indexes);
        $a = array_map(
            fn (int $i) => [...array_map(fn (int $j) => $q[$i][$j], $indexes), $c[$i]],
            $indexes,
        );

        for ($col = 0; $col < $m; $col++) {
            $pivot = $col;
            for ($row = $col + 1; $row < $m; $row++) {
                if (abs($a[$row][$col]) > abs($a[$pivot][$col])) {
                    $pivot = $row;
                }
            }
            [$a[$col], $a[$pivot]] = [$a[$pivot], $a[$col]];
            if (abs($a[$col][$col]) <= 1e-12) {
                continue;
            }
            for ($row = $col + 1; $row < $m; $row++) {
                $factor = $a[$row][$col] / $a[$col][$col];
                for ($k = $col; $k <= $m; $k++) {
                    $a[$row][$k] -= $factor * $a[$col][$k];
                }
            }
        }

        $s = array_fill(0, $m, 0.0);
        for ($row = $m - 1; $row >= 0; $row--) {
            $sum = $a[$row][$m];
            for ($k = $row + 1; $k < $m; $k++) {
                $sum -= $a[$row][$k] * $s[$k];
            }
            $s[$row] = abs($a[$row][$row]) > 1e-12 ? $sum / $a[$row][$row] : 0.0;
        }

        return $s;
    }

    /**
     * @param  list<float>  $u
     * @param  list<float>  $v
     */
    private function dot(array $u, array $v): float
    {
        $sum = 0.0;
        foreach ($u as $i => $value) {
            $sum += $value * $v[$i];
        }

        return $sum;
    }
}
