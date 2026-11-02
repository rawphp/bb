<?php

namespace App\Domain\MealFit;

use App\Domain\Model\MealTarget;

/** A food in a meal, with its amount in grams in the `weighing` mode. */
final readonly class MealFood
{
    /**
     * @param  string  $id  Names this food within its meal. MealFit refuses a meal where two foods share one.
     * @param  bool  $isLocked  MEAL-005: a locked food keeps its exact amount.
     */
    public function __construct(
        public string $id,
        public Food $food,
        public float $grams,
        public Weighing $weighing = Weighing::Raw,
        public bool $isLocked = false,
    ) {}

    /** FOOD-006: the raw weight behind `grams`. Cooked weight only differs when the food has a ratio. */
    public function rawGrams(): float
    {
        $ratio = self::cookedRatio($this->food, $this->weighing);

        return $ratio === null ? $this->grams : $this->grams / $ratio;
    }

    /** FOOD-006: raw and cooked give the same macros, because both go through rawGrams(). */
    public function macros(): MealTarget
    {
        $scale = $this->rawGrams() / 100;

        return new MealTarget(
            kcal: $this->food->kcal * $scale,
            proteinG: $this->food->proteinG * $scale,
            fatG: $this->food->fatG * $scale,
            carbsG: $this->food->carbsG * $scale,
        );
    }

    /**
     * FOOD-005: the first serving, counted to 0.1. Null when the food has none.
     * Servings are measured as sold, so this counts raw grams.
     */
    public function household(): ?Household
    {
        $serving = $this->food->servings[0] ?? null;

        if ($serving === null || $serving->grams <= 0) {
            return null;
        }

        return new Household($serving->label, round($this->rawGrams() / $serving->grams * 10) / 10);
    }

    /** FOOD-006: the same food weighed the other way, same macros, grams rounded to 1 g. */
    public function weighedAs(Weighing $mode): self
    {
        return new self(
            $this->id, $this->food, round(self::gramsForRaw($this->rawGrams(), $this->food, $mode)), $mode, $this->isLocked,
        );
    }

    public function withGrams(float $grams): self
    {
        return new self($this->id, $this->food, $grams, $this->weighing, $this->isLocked);
    }

    public function withLocked(bool $isLocked): self
    {
        return new self($this->id, $this->food, $this->grams, $this->weighing, $isLocked);
    }

    /** The grams to show for `raw` grams of the food in the `weighing` mode. */
    public static function gramsForRaw(float $raw, Food $food, Weighing $weighing): float
    {
        $ratio = self::cookedRatio($food, $weighing);

        return $ratio === null ? $raw : $raw * $ratio;
    }

    /** FOOD-006: the ratio to apply, or null when the grams are raw grams. A ratio of 0 or less counts as none. */
    private static function cookedRatio(Food $food, Weighing $weighing): ?float
    {
        $ratio = $food->cookedRatio;

        return $weighing === Weighing::Cooked && $ratio !== null && $ratio > 0 ? $ratio : null;
    }
}
