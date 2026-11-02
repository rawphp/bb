<?php

use App\Domain\Foods\CustomFoodServing;

/** One 30 g piece with 6 g protein, 3 g fat, 12 g carbs and 1 g fiber: rp-clone's protein bar. */
function proteinBar(float $size = 1, string $unit = 'piece', float $grams = 30, float $protein = 6, float $fat = 3, float $carbs = 12, float $fiber = 1): CustomFoodServing
{
    return new CustomFoodServing($size, $unit, $grams, $protein, $fat, $carbs, $fiber);
}

it('FOOD-060 works out a serving\'s calories as 4 per gram of protein and carbs and 9 per gram of fat', function () {
    expect(proteinBar()->kcal())->toBe(99.0);
});

it('FOOD-060 does not count fiber in calories', function () {
    expect(proteinBar(protein: 5, fat: 0, carbs: 0, fiber: 5)->kcal())->toBe(20.0);
});

it('FOOD-060 stores the serving\'s nutrition per 100 g, rounded to 2 places, with calories from those values', function () {
    expect(proteinBar()->per100g())->toBe([
        'kcal' => 330.0,
        'protein' => 20.0,
        'fat' => 10.0,
        'carbs' => 40.0,
        'fiber' => 3.33,
    ]);
});

it('FOOD-060 keeps every value the user entered, so the edit form opens with them', function () {
    $serving = proteinBar(size: 1.5, unit: 'tbsp', grams: 33.333, protein: 6.125);

    expect($serving->toArray())->toBe([
        'size' => 1.5, 'unit' => 'tbsp', 'grams' => 33.333,
        'protein' => 6.125, 'fat' => 3.0, 'carbs' => 12.0, 'fiber' => 1.0,
    ])->and(CustomFoodServing::fromArray($serving->toArray()))->toEqual($serving);
});

it('FOOD-060 reads a stored serving whose whole numbers came back as integers', function () {
    $stored = ['size' => 1, 'unit' => 'piece', 'grams' => 30, 'protein' => 6, 'fat' => 3, 'carbs' => 12, 'fiber' => 1];

    expect(CustomFoodServing::fromArray($stored))->toEqual(proteinBar());
});

it('FOOD-060 names a serving by its size and unit', function () {
    expect(proteinBar()->label())->toBe('1 piece')
        ->and(proteinBar(size: 1.5, unit: 'tbsp')->label())->toBe('1.5 tbsp')
        ->and(proteinBar(size: 100, unit: 'g')->label())->toBe('100 g');
});

it('FOOD-061 says a row\'s serving and its calories', function () {
    expect(proteinBar()->detail())->toBe('1 piece · 99 kcal');
});

it('FOOD-061 shows a fractional serving without trailing zeros and its calories rounded', function () {
    // 4 × 6 + 4 × 12.15 + 9 × 3 = 99.6
    expect(proteinBar(size: 1.5, unit: 'tbsp', carbs: 12.15)->detail())->toBe('1.5 tbsp · 100 kcal');
});

it('FOOD-061 reads no serving from a stored value that is missing, partial or not a list of values', function (mixed $stored) {
    expect(CustomFoodServing::fromStored($stored))->toBeNull();
})->with([
    'missing' => [null],
    'partial' => [['size' => 1, 'unit' => 'cup', 'grams' => 95]],
    'a string' => ['1 cup'],
    'a unit that is not text' => [['size' => 1, 'unit' => 3, 'grams' => 95, 'protein' => 10, 'fat' => 16, 'carbs' => 58, 'fiber' => 6]],
    'a weight that is not a number' => [['size' => 1, 'unit' => 'cup', 'grams' => 'lots', 'protein' => 10, 'fat' => 16, 'carbs' => 58, 'fiber' => 6]],
]);

it('FOOD-061 reads a complete stored serving', function () {
    expect(CustomFoodServing::fromStored(['size' => 1, 'unit' => 'piece', 'grams' => 30, 'protein' => 6, 'fat' => 3, 'carbs' => 12, 'fiber' => 1]))
        ->toEqual(proteinBar());
});

it('FOOD-061 shows a food kept without its serving as 100 g of it', function () {
    $serving = CustomFoodServing::hundredGrams(protein: 20, fat: 10, carbs: 40, fiber: 3.33);

    expect($serving->toArray())->toBe(['size' => 100.0, 'unit' => 'g', 'grams' => 100.0, 'protein' => 20.0, 'fat' => 10.0, 'carbs' => 40.0, 'fiber' => 3.33])
        ->and($serving->detail())->toBe('100 g · 330 kcal');
});
