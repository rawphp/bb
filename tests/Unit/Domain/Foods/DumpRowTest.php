<?php

use App\Domain\Foods\DumpRow;
use App\Models\Food;

/** @return list<array<string, mixed>> the records of a USDA fixture */
function foodsUsdaRecords(string $file): array
{
    $json = json_decode((string) file_get_contents(base_path('tests/fixtures/foods/'.$file)), true);

    return array_values($json)[0];
}

/** @return list<array<string, mixed>> the products of an Open Food Facts fixture */
function foodsOffRecords(string $file): array
{
    $lines = file(base_path('tests/fixtures/foods/'.$file), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    return array_map(fn (string $line) => json_decode($line, true), $lines);
}

/** @return array<string, array{food: array<string, mixed>, servings: list<array{label: string, grams: float}>}|null> by source id */
function foodsMapped(string $file): array
{
    $mapper = new DumpRow;
    $branded = str_contains($file, 'branded');
    $rows = [];
    if (str_starts_with($file, 'usda')) {
        foreach (foodsUsdaRecords($file) as $record) {
            $rows[(string) $record['fdcId']] = $mapper->fromUsda($record, $branded);
        }
    } else {
        foreach (foodsOffRecords($file) as $record) {
            $rows[(string) $record['code']] = $mapper->fromOpenFoodFacts($record);
        }
    }

    return $rows;
}

it('FOOD-004 maps USDA Foundation foods to nutrients per 100 g, portions and diet flags', function () {
    $banana = foodsMapped('usda_foundation.json')['1105073'];

    expect($banana['food'])->toMatchArray([
        'name' => 'Bananas, ripe and slightly ripe, raw', 'brand' => null, 'barcode' => null,
        'source' => 'usda', 'source_id' => '1105073', 'kind' => 'generic',
        'kcal' => 97.0, 'protein' => 0.73, 'fat' => 0.29, 'carbs' => 23.0, 'fiber' => 2.6,
        'vegan' => true, 'paleo' => true,
    ])
        ->and($banana['food'])->not->toHaveKeys(['curated', 'curated_group', 'user_id'])
        ->and($banana['servings'])->toBe([['label' => 'medium', 'grams' => 118.0], ['label' => '1 cup, sliced', 'grams' => 150.0]]);
});

it('FOOD-004 keys USDA nutrients by their exact number, so carbs by summation (205.2) never replace carbs by difference (205)', function () {
    $nutrient = fn (string $number, float $amount) => ['nutrient' => ['number' => $number], 'amount' => $amount];
    $record = ['fdcId' => 1, 'description' => 'Bananas, raw', 'foodCategory' => ['description' => 'Fruits and Fruit Juices'],
        'foodNutrients' => [$nutrient('208', 97.0), $nutrient('205', 23.0), $nutrient('205.2', 15.0), $nutrient('291', 2.6), $nutrient('291.1', 9.0)]];

    expect((new DumpRow)->fromUsda($record, false)['food'])->toMatchArray(['kcal' => 97.0, 'carbs' => 23.0, 'fiber' => 2.6]);
});

it('FOOD-004 falls back to Atwater energy when nutrient 208 is missing', function () {
    $hummus = foodsMapped('usda_foundation.json')['2262075'];

    expect($hummus['food'])->toMatchArray(['kcal' => 229.0, 'fiber' => 0.0, 'low_fodmap' => false])
        ->and($hummus['servings'])->toBe([]);
});

it('FOOD-008 maps SR Legacy drinks and per-unit household weights', function () {
    $rows = foodsMapped('usda_sr_legacy.json');

    expect($rows['171890']['food'])->toMatchArray(['name' => 'Beverages, coffee, brewed, prepared with tap water', 'kcal' => 1.0])
        ->and($rows['171890']['servings'])->toBe([['label' => 'cup', 'grams' => 237.0]])
        ->and($rows['328637']['food'])->toMatchArray(['dairy_free' => false, 'vegetarian' => true])
        ->and($rows['328637']['servings'])->toBe([['label' => 'oz', 'grams' => 28.35], ['label' => 'slice (1 oz)', 'grams' => 28.0]]);
});

it('FOOD-004 maps USDA Branded foods with barcode, brand, serving and kind', function () {
    $rows = foodsMapped('usda_branded.json');

    expect($rows['1990001']['food'])->toMatchArray([
        'brand' => 'GOOD LOAF', 'barcode' => '12345678905', 'kind' => 'packaged',
        'gluten_free' => false, 'vegan' => true, 'paleo' => false,
    ])
        ->and($rows['1990001']['servings'])->toBe([['label' => '1 slice', 'grams' => 43.0]])
        ->and($rows['1990002']['food'])->toMatchArray([
            'kind' => 'restaurant', 'barcode' => null, 'vegan' => false, 'gluten_free' => false, 'dairy_free' => false, 'paleo' => false,
        ])
        ->and($rows['1990002']['servings'])->toBe([]);
});

it('FOOD-001 maps Open Food Facts products and skips those without a name', function () {
    $rows = foodsMapped('off.jsonl');

    expect($rows['3017620422003']['food'])->toMatchArray([
        'name' => 'Hazelnut spread', 'brand' => 'Ferrero', 'barcode' => '3017620422003', 'source' => 'off', 'kind' => 'packaged',
        'kcal' => 539.0, 'protein' => 6.3, 'vegetarian' => true, 'vegan' => false, 'dairy_free' => false,
    ])
        ->and($rows['3017620422003']['servings'])->toBe([['label' => '15 g', 'grams' => 15.0]])
        ->and(round($rows['7350000000012']['food']['kcal'], 1))->toBe(45.4)
        ->and($rows['7350000000012']['food'])->toMatchArray(['fiber' => 0.8, 'vegan' => true, 'gluten_free' => false])
        ->and($rows['7350000000012']['servings'])->toBe([['label' => '250 ml', 'grams' => 250.0]])
        ->and($rows['5000000000017']['food'])->toMatchArray(['name' => 'Big Burger', 'kind' => 'restaurant', 'fiber' => 0.0])
        ->and($rows['5000000000017']['servings'])->toBe([])
        ->and($rows['5000000000024'])->toBeNull();
});

it('FOOD-001 keeps a food past an out-of-range serving or a long code, and skips non-finite numbers', function () {
    $rows = foodsMapped('off_edge.jsonl');
    $long = str_repeat('1234567890', 4);

    expect($rows['1000000000001']['servings'])->toBe([])
        ->and($rows[$long]['food']['barcode'])->toBeNull()
        ->and($rows['1000000000002'])->toBeNull()
        ->and($rows['1000000000004']['servings'])->toBe([['label' => '30 g', 'grams' => 30.0]]);
});

it('FOOD-004 falls back to the English ingredients when ingredients_text is empty', function () {
    expect(foodsMapped('off_edge.jsonl')['1000000000003']['food'])->toMatchArray(['gluten_free' => false, 'vegan' => true]);
});

it('FOOD-004 classifies only English ingredients, so another language sets no diet flag', function () {
    $rows = foodsMapped('off_edge.jsonl');

    expect(array_filter(array_intersect_key($rows['1000000000005']['food'], array_flip(Food::DIETS))))->toBe([])
        ->and($rows['1000000000006']['food'])->toMatchArray(['gluten_free' => false, 'dairy_free' => false, 'vegetarian' => true]);
});

it('FOOD-001 drops USDA portions that are zero, huge or over 5000 g', function () {
    expect(foodsMapped('usda_edge.json')['3000001']['servings'])->toBe([['label' => 'cup chopped', 'grams' => 91.0]]);
});

it('FOOD-001 skips records with impossible values', function (array $nutriments) {
    expect((new DumpRow)->fromOpenFoodFacts(['code' => '1', 'product_name' => 'Bad', 'nutriments' => $nutriments]))->toBeNull();
})->with([
    'kcal over 1000' => [['energy-kcal_100g' => 1001]],
    'negative kcal' => [['energy-kcal_100g' => -1]],
    'macro over 100 g' => [['energy-kcal_100g' => 100, 'proteins_100g' => 101]],
    'negative macro' => [['energy-kcal_100g' => 100, 'fat_100g' => -0.1]],
    'no energy' => [['proteins_100g' => 10]],
]);

it('FOOD-001 skips USDA records without an id, a name or energy, and ignores malformed fields', function () {
    $mapper = new DumpRow;
    $energy = [['nutrient' => ['number' => '208'], 'amount' => 50]];

    expect($mapper->fromUsda(['description' => 'No id', 'foodNutrients' => $energy], false))->toBeNull()
        ->and($mapper->fromUsda(['fdcId' => 1, 'foodNutrients' => $energy], false))->toBeNull()
        ->and($mapper->fromUsda(['fdcId' => 1, 'description' => 'No energy', 'foodNutrients' => 'oops'], false))->toBeNull()
        ->and($mapper->fromUsda(['fdcId' => 1, 'description' => 'Odd portions', 'foodNutrients' => $energy, 'foodPortions' => ['x', null]], false))
        ->toMatchArray(['servings' => []]);
});
