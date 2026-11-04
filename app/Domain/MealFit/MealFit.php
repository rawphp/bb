<?php

namespace App\Domain\MealFit;

use App\Domain\Model\MealFitRules;
use App\Domain\Model\MealTarget;

/** MEAL-002 to MEAL-007: scales the grams of the foods a user picked so the meal hits its targets. */
final readonly class MealFit implements MealFitRules
{
    /** MEAL-003: "Targets met" when every macro is within this many grams of target. */
    public const TOLERANCE = 5.0;

    /** MEAL-002: the fit never gives one unlocked food more than this many grams, as shown. */
    public const MAX_GRAMS = 600.0;

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
     *
     * @throws \InvalidArgumentException when two foods share an id
     */
    public function fit(array $foods, MealTarget $target): MealFitResult
    {
        $this->assertUniqueIds($foods);

        return $this->solve($foods, $this->lockedIds($foods), $target);
    }

    /**
     * MEAL-004: the edited food takes `grams` (to 1 g) and stays put while the other unlocked
     * foods rebalance. Its lock flag is untouched. An unknown id is a plain fit.
     *
     * @param  list<MealFood>  $foods
     *
     * @throws \InvalidArgumentException when two foods share an id
     */
    public function setAmount(float $grams, string $id, array $foods, MealTarget $target): MealFitResult
    {
        $this->assertUniqueIds($foods);
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
     * Locks and edits are keyed on the id, so two foods sharing one cannot be told apart.
     *
     * @param  list<MealFood>  $foods
     */
    private function assertUniqueIds(array $foods): void
    {
        $seen = [];

        foreach ($foods as $food) {
            if (isset($seen[$food->id])) {
                throw new \InvalidArgumentException("Two foods in the meal share the id '{$food->id}'.");
            }

            $seen[$food->id] = true;
        }
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
     * Fits the foods not held, each between 0 and MAX_GRAMS as shown. A food the fit puts above
     * the cap is held there and the rest are fitted again; a capped food the refit would rather
     * have lower is let go again. What the cap leaves short is a shortfall (MEAL-007).
     *
     * @param  list<MealFood>  $foods
     * @param  list<string>  $held
     */
    private function solve(array $foods, array $held, MealTarget $target): MealFitResult
    {
        $free = array_keys(array_filter($foods, fn (MealFood $f) => ! in_array($f->id, $held, true)));

        if ($free === []) {
            return $this->result($foods, $target);
        }

        $fixed = $this->totals(array_values(array_filter($foods, fn (MealFood $f) => in_array($f->id, $held, true))));
        $b = [$target->proteinG - $fixed->proteinG, $target->fatG - $fixed->fatG, $target->carbsG - $fixed->carbsG];
        $columns = array_map(fn (int $i) => [$foods[$i]->food->proteinG, $foods[$i]->food->fatG, $foods[$i]->food->carbsG], $free);
        $caps = array_map(fn (int $i) => $foods[$i]->weighing->rawGrams(self::MAX_GRAMS, $foods[$i]->food->cookedRatio) / 100, $free);
        $units = $this->boundedLeastSquares($columns, $b, $caps);

        foreach ($free as $k => $i) {
            $food = $foods[$i];
            $grams = round(MealFood::gramsForRaw($units[$k] * 100, $food->food, $food->weighing));
            $foods[$i] = $food->withGrams(min($grams, self::MAX_GRAMS));
        }

        return $this->result($foods, $target);
    }

    /**
     * Minimises |A x - b|^2 + ridge |x|^2 over 0 <= x <= caps. Foods at their cap are held there
     * while the others are fitted; one over its cap is moved to the cap, and one at its cap whose
     * gradient points down is freed again, a step at a time.
     *
     * @param  list<list<float>>  $columns
     * @param  list<float>  $b
     * @param  list<float>  $caps
     * @return list<float>
     */
    private function boundedLeastSquares(array $columns, array $b, array $caps): array
    {
        $n = count($columns);
        $atCap = [];

        for ($round = 0; $round < 10 * $n + 10; $round++) {
            $rest = $b;
            foreach ($atCap as $j) {
                foreach ($rest as $r => $value) {
                    $rest[$r] = $value - $columns[$j][$r] * $caps[$j];
                }
            }

            $open = array_values(array_diff(range(0, $n - 1), $atCap));
            $x = array_fill(0, $n, 0.0);
            foreach ($atCap as $j) {
                $x[$j] = $caps[$j];
            }
            foreach ($this->nonNegativeLeastSquares(array_map(fn (int $j) => $columns[$j], $open), $rest) as $k => $value) {
                $x[$open[$k]] = max(0.0, $value);
            }

            $over = null;
            foreach ($open as $j) {
                if ($x[$j] > $caps[$j] + self::EPSILON && ($over === null || $x[$j] / $caps[$j] > $x[$over] / $caps[$over])) {
                    $over = $j;
                }
            }
            if ($over !== null) {
                $atCap[] = $over;

                continue;
            }

            $release = null;
            $lowest = -self::EPSILON;
            foreach ($atCap as $j) {
                $gradient = $this->dot($columns[$j], $this->residual($columns, $b, $x)) - self::RIDGE * $x[$j];
                if ($gradient < $lowest) {
                    [$release, $lowest] = [$j, $gradient];
                }
            }
            if ($release === null) {
                return $x;
            }
            $atCap = array_values(array_diff($atCap, [$release]));
        }

        return array_map(fn (float $v, float $cap) => min($v, $cap), $x, $caps);
    }

    /**
     * b - A x.
     *
     * @param  list<list<float>>  $columns
     * @param  list<float>  $b
     * @param  list<float>  $x
     * @return list<float>
     */
    private function residual(array $columns, array $b, array $x): array
    {
        foreach ($columns as $j => $column) {
            foreach ($b as $r => $value) {
                $b[$r] = $value - $column[$r] * $x[$j];
            }
        }

        return $b;
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
