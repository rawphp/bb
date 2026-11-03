<?php

namespace App\Domain\MealFit;

use App\Domain\Model\MealTarget;
use App\Domain\Pantry\FoodUnit;
use App\Domain\Recipe\RecipeServing;

/**
 * MEAL-002 to MEAL-008, RCP-002: what an edit does to a meal's foods and recipe servings. A recipe serving is a
 * MealFood of {@see RecipeServing::GRAMS_PER_SERVING} g a serving. Every edit answers the meal's new list in the order
 * it came in; the caller saves it, through {@see planned()}.
 */
final readonly class MealEdit
{
    /** MEAL-004: the step of a food's + and −, in grams. */
    public const STEP_GRAMS = 5.0;

    /** MEAL-004, RCP-002: the step of a serving's + and −, in servings. */
    public const STEP_SERVINGS = 0.5;

    public function __construct(private MealFit $fit) {}

    /**
     * MEAL-002, RCP-002: the foods joining the meal, then the whole meal fitted to its target. Locked ones keep their
     * amounts (MEAL-005). A food already in the meal is the caller's to skip: it knows the food, not just the entry.
     *
     * @param  list<MealFood>  $meal
     * @param  list<MealFood>  $joining
     * @return list<MealFood>
     */
    public function add(array $meal, array $joining, MealTarget $target): array
    {
        return $this->rebalance([...$meal, ...$joining], $target);
    }

    /**
     * MEAL-006: every unlocked entry fitted to the target again.
     *
     * @param  list<MealFood>  $meal
     * @return list<MealFood>
     */
    public function rebalance(array $meal, MealTarget $target): array
    {
        return $meal === [] ? [] : $this->fit->fit($meal, $target)->foods;
    }

    /**
     * MEAL-004: the entry takes `$grams` and stays put while the other unlocked entries rebalance. An entry that is
     * not in the meal changes nothing.
     *
     * @param  list<MealFood>  $meal
     * @return list<MealFood>
     */
    public function setAmount(array $meal, string $id, float $grams, MealTarget $target): array
    {
        if (! $this->has($meal, $id)) {
            return $meal;
        }

        return $this->fit->setAmount($grams, $id, $meal, $target)->foods;
    }

    /**
     * MEAL-004, RCP-002: + adds a step, STEP_GRAMS to a food and STEP_SERVINGS to a serving; − takes it off, a food
     * down to 0 g and a serving down to a tenth. − on an entry already there changes nothing, and refits nothing.
     *
     * @param  list<MealFood>  $meal
     * @return list<MealFood>
     */
    public function step(array $meal, string $id, bool $up, bool $isServing, MealTarget $target): array
    {
        $entry = $this->find($meal, $id);
        if ($entry === null) {
            return $meal;
        }

        $step = $isServing ? self::STEP_SERVINGS * RecipeServing::GRAMS_PER_SERVING : self::STEP_GRAMS;
        $floor = $isServing ? RecipeServing::MIN_GRAMS : 0.0;
        if (! $up && $entry->grams <= $floor) {
            return $meal;
        }

        return $this->setAmount($meal, $id, $up ? $entry->grams + $step : max($floor, $entry->grams - $step), $target);
    }

    /**
     * MEAL-005: the lock flag and nothing else. Nothing refits.
     *
     * @param  list<MealFood>  $meal
     * @return list<MealFood>
     */
    public function lock(array $meal, string $id, bool $locked): array
    {
        return array_map(fn (MealFood $entry) => $entry->id === $id ? $entry->withLocked($locked) : $entry, $meal);
    }

    /**
     * MEAL-008: the entry goes. Nothing else changes and nothing refits.
     *
     * @param  list<MealFood>  $meal
     * @return list<MealFood>
     */
    public function remove(array $meal, string $id): array
    {
        return array_values(array_filter($meal, fn (MealFood $entry) => $entry->id !== $id));
    }

    /**
     * MEAL-004, RCP-002: a typed amount as the grams the fit works in. A food takes g, oz or its first household unit,
     * counted in raw grams and shown in the weighing it has; a serving takes servings, at least a tenth. Null for a
     * unit the entry does not have: a food with no household unit, or a unit of the other kind of entry.
     */
    public function gramsFor(MealFood $entry, bool $isServing, float $value, string $unit): ?float
    {
        if ($isServing) {
            return $unit === 'servings' ? max(RecipeServing::MIN_GRAMS, $value * RecipeServing::GRAMS_PER_SERVING) : null;
        }

        return match ($unit) {
            FoodUnit::Grams->value => $value,
            FoodUnit::Ounces->value => $value * FoodUnit::GRAMS_PER_OUNCE,
            'household' => $this->householdGrams($entry, $value),
            default => null,
        };
    }

    /**
     * What is saved: an entry fitted or typed to nothing stays in the meal at the least the server holds, 1 g of a
     * food or a tenth of a serving (MealFood grams of RecipeServing::MIN_GRAMS).
     *
     * @param  list<MealFood>  $meal
     * @return list<MealFood>
     */
    public function planned(array $meal): array
    {
        return array_map(fn (MealFood $entry) => $entry->grams < RecipeServing::MIN_GRAMS ? $entry->withGrams(RecipeServing::MIN_GRAMS) : $entry, $meal);
    }

    private function householdGrams(MealFood $entry, float $count): ?float
    {
        $serving = $entry->food->servings[0] ?? null;

        return $serving === null || $serving->grams <= 0
            ? null
            : MealFood::gramsForRaw($count * $serving->grams, $entry->food, $entry->weighing);
    }

    /** @param  list<MealFood>  $meal */
    private function find(array $meal, string $id): ?MealFood
    {
        foreach ($meal as $entry) {
            if ($entry->id === $id) {
                return $entry;
            }
        }

        return null;
    }

    /** @param  list<MealFood>  $meal */
    private function has(array $meal, string $id): bool
    {
        return $this->find($meal, $id) !== null;
    }
}
