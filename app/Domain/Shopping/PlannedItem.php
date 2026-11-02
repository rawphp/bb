<?php

namespace App\Domain\Shopping;

/** One thing planned in a meal: a food, or servings of a recipe. */
final readonly class PlannedItem
{
    private function __construct(
        public ?FoodAmount $food,
        public ?Recipe $recipe,
        public float $servings,
    ) {}

    public static function food(FoodAmount $amount): self
    {
        return new self($amount, null, 0);
    }

    public static function recipe(Recipe $recipe, float $servings): self
    {
        return new self(null, $recipe, $servings);
    }

    /**
     * SHOP-002: recipes break down into their ingredients, scaled to the servings planned. No servings, of the recipe
     * or planned, give nothing.
     *
     * @return list<FoodAmount>
     */
    public function foodAmounts(): array
    {
        if ($this->food !== null) {
            return [$this->food];
        }

        if ($this->recipe === null || $this->recipe->servings <= 0 || $this->servings <= 0) {
            return [];
        }

        $scale = $this->servings / $this->recipe->servings;

        return array_map(fn (FoodAmount $ingredient) => $ingredient->scaled($scale), $this->recipe->ingredients);
    }
}
