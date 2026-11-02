<?php

use App\Domain\Foods\FoodKind;
use App\Domain\Foods\FoodSearchFilters;
use App\Domain\MealFit\CuratedGroup;

it('FOOD-025 and FOOD-026 search only curated foods under the curated filter or a curated group', function () {
    expect((new FoodSearchFilters)->curatedOnly())->toBeFalse()
        ->and((new FoodSearchFilters(FoodKind::Packaged))->curatedOnly())->toBeFalse()
        ->and((new FoodSearchFilters(curated: true))->curatedOnly())->toBeTrue()
        ->and((new FoodSearchFilters(group: CuratedGroup::Veggies))->curatedOnly())->toBeTrue()
        ->and((new FoodSearchFilters)->limit)->toBe(25);
});
