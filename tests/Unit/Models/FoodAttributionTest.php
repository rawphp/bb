<?php

use App\Domain\Foods\FoodSource;
use App\Models\Food;

function foodsFrom(string $source): Food
{
    return (new Food)->forceFill(['source' => $source]);
}

it('FOOD-001 gives an Open Food Facts food the ODbL credit for its food detail, and USDA its own', function () {
    expect(foodsFrom('off')->attribution())->toBe(FoodSource::OpenFoodFacts->attribution())->toContain('Open Database License (ODbL)')
        ->and(foodsFrom('usda')->attribution())->toBe(FoodSource::Usda->attribution())
        ->and(foodsFrom('curated')->attribution())->toBeNull()
        ->and(foodsFrom('custom')->attribution())->toBeNull();
});
