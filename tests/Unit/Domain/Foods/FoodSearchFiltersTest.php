<?php

use App\Capabilities\Nutrition\Data\FoodSearchInput;
use App\Domain\Foods\FoodKind;
use App\Domain\Foods\FoodSearchFilters;
use App\Domain\Foods\FoodSearchSource;
use App\Domain\MealFit\CuratedGroup;
use Illuminate\Validation\Rules\In;

it('FOOD-025 and FOOD-026 search only curated foods under the Curated source or a curated group', function () {
    expect((new FoodSearchFilters)->curatedOnly())->toBeFalse()
        ->and((new FoodSearchFilters(FoodSearchSource::Packaged))->curatedOnly())->toBeFalse()
        ->and((new FoodSearchFilters(FoodSearchSource::Curated))->curatedOnly())->toBeTrue()
        ->and((new FoodSearchFilters(group: CuratedGroup::Veggies))->curatedOnly())->toBeTrue()
        ->and((new FoodSearchFilters)->limit)->toBe(25);
});

it('FOOD-025 searches only the user\'s own foods under Recents, Favorites or Custom, and narrows kinds under Restaurants or Packaged', function () {
    foreach (FoodSearchSource::cases() as $source) {
        expect((new FoodSearchFilters($source))->ownOnly())->toBe(in_array($source, [FoodSearchSource::Recents, FoodSearchSource::Favorites, FoodSearchSource::Custom], true), $source->value);
    }
    expect(FoodSearchSource::Restaurants->kind())->toBe(FoodKind::Restaurant)
        ->and(FoodSearchSource::Packaged->kind())->toBe(FoodKind::Packaged)
        ->and(FoodSearchSource::Recents->kind())->toBeNull()
        ->and((new FoodSearchFilters(FoodSearchSource::Recents, CuratedGroup::Veggies))->ownOnly())->toBeFalse();
});

it('FOOD-025 offers exactly the sources food_search takes', function () {
    $rule = collect(FoodSearchInput::rules()['source'])->first(fn ($r) => $r instanceof In);

    expect((string) $rule)->toBe('in:"'.implode('","', array_column(FoodSearchSource::cases(), 'value')).'"');
});
