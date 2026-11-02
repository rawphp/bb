<?php

use App\Domain\MealFit\Food;
use App\Domain\Pantry\FoodUnit;
use App\Domain\Pantry\PantryFood;

// What a Pantry row shows of a food (rp-clone PantryModel.row(for:)).

function riceFood(?float $cookedRatio): PantryFood
{
    return new PantryFood(new Food(id: 'rice', name: 'White rice', kcal: 360, proteinG: 7, fatG: 0.6, carbsG: 80, cookedRatio: $cookedRatio));
}

it('FOOD-006 a food with a cooked ratio shows its raw and its cooked nutrition per 100 g', function () {
    $rice = riceFood(2.0);

    expect($rice->raw(FoodUnit::Grams)->kcal)->toBe(360.0)
        ->and($rice->cooked(FoodUnit::Grams)?->kcal)->toBe(180.0)
        ->and($rice->cooked(FoodUnit::Grams)?->carbsG)->toBe(40.0);
});

it('FOOD-006 a food without a cooked ratio has no cooked nutrition, so no Raw/Cooked toggle', function () {
    expect(riceFood(null)->cooked(FoodUnit::Grams))->toBeNull()
        ->and(riceFood(0.0)->cooked(FoodUnit::Grams))->toBeNull();
});

it('FOOD-009 in ounces a row reads per 3.5 oz, its nutrition scaled to that weight', function () {
    $rice = riceFood(2.0);
    $grams = 3.5 * 28.349523125;

    expect(FoodUnit::Grams->basisText())->toBe('per 100 g')
        ->and(FoodUnit::Ounces->basisText())->toBe('per 3.5 oz')
        ->and($rice->raw(FoodUnit::Ounces)->kcal)->toEqualWithDelta(360 * $grams / 100, 1e-9)
        ->and($rice->raw(FoodUnit::Ounces)->proteinG)->toEqualWithDelta(7 * $grams / 100, 1e-9)
        ->and($rice->cooked(FoodUnit::Ounces)?->kcal)->toEqualWithDelta(180 * $grams / 100, 1e-9);
});
