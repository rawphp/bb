<?php

use App\Domain\Foods\FoodSource;

it('FOOD-001 imports only the USDA and Open Food Facts datasets', function () {
    expect(FoodSource::importable('usda'))->toBe(FoodSource::Usda)
        ->and(FoodSource::importable('off'))->toBe(FoodSource::OpenFoodFacts)
        ->and(FoodSource::importable('curated'))->toBeNull()
        ->and(FoodSource::importable('custom'))->toBeNull()
        ->and(FoodSource::importable('nope'))->toBeNull();
});

it('FOOD-001 credits Open Food Facts under the ODbL, and USDA FoodData Central', function () {
    expect(FoodSource::OpenFoodFacts->attribution())->toContain('Open Food Facts')->toContain('Open Database License (ODbL)')
        ->and(FoodSource::Usda->attribution())->toContain('USDA FoodData Central')
        ->and(FoodSource::Curated->attribution())->toBeNull()
        ->and(FoodSource::Custom->attribution())->toBeNull()
        ->and(FoodSource::attributions())->toBe([FoodSource::Usda->attribution(), FoodSource::OpenFoodFacts->attribution()]);
});
