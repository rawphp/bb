<?php

namespace App\Capabilities\Reads\Pantry;

use App\Capabilities\Nutrition\Data\FoodRow;
use App\Capabilities\Nutrition\Data\Macros;
use App\Domain\Model\MealTarget;
use App\Domain\Pantry\FoodUnit;
use App\Domain\Pantry\PantryFood;

/** Pantry foods as rows (FOOD-006, FOOD-009, FOOD-022, FOOD-028): in the unit asked for, with their favourite and in-meal marks. */
final readonly class FoodRows
{
    /**
     * @param  list<string>  $favoriteIds
     * @param  list<string>  $mealFoodIds
     */
    public function __construct(
        private FoodUnit $unit,
        private array $favoriteIds,
        private array $mealFoodIds,
    ) {}

    /**
     * @param  list<PantryFood>  $foods
     * @return list<FoodRow>
     */
    public function rows(array $foods): array
    {
        return array_map(fn (PantryFood $food) => new FoodRow(
            food_id: $food->id(),
            name: $food->food->name,
            detail: $food->brand,
            raw: self::macros($food->raw($this->unit)),
            cooked: ($cooked = $food->cooked($this->unit)) === null ? null : self::macros($cooked),
            basis_text: $this->unit->basisText(),
            is_curated: $food->isCurated,
            is_favorite: in_array($food->id(), $this->favoriteIds, true),
            is_in_meal: in_array($food->id(), $this->mealFoodIds, true),
        ), $foods);
    }

    /** "120 kcal", "P 22.5", "C 0": calories whole, macros to 0.1 g without a trailing zero. */
    private static function macros(MealTarget $macros): Macros
    {
        return new Macros(
            kcal: number_format(round($macros->kcal) + 0.0).' kcal',
            protein: 'P '.self::tenth($macros->proteinG),
            fat: 'F '.self::tenth($macros->fatG),
            carbs: 'C '.self::tenth($macros->carbsG),
        );
    }

    private static function tenth(float $grams): string
    {
        $rounded = round($grams, 1) + 0.0;

        return number_format($rounded, $rounded === floor($rounded) ? 0 : 1);
    }
}
