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

it('FOOD-001 carries the dataset credits in the food row and in Settings, About', function () {
    $json = fn (string $file) => json_decode((string) file_get_contents(base_path("tests/fixtures/screens/{$file}")), true, flags: JSON_THROW_ON_ERROR);
    $rows = collect($json('food_search.json')['sections'])->flatMap(fn (array $section) => $section['rows'])->keyBy('name');

    expect($rows['Chicken noodle soup']['attribution'])->toBe(FoodSource::OpenFoodFacts->attribution())
        ->and($rows['Chicken breast']['attribution'])->toBeNull()
        ->and($json('food_barcode_lookup.json')['food']['attribution'])->toBe(FoodSource::OpenFoodFacts->attribution());
    foreach (['settings_show.json', 'settings_show.empty.json', 'settings_show.warning.json'] as $file) {
        expect($json($file)['attributions'])->toBe(FoodSource::attributions(), $file);
    }
});
