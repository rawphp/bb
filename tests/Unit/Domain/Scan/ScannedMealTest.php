<?php

use App\Domain\MealFit\Food;
use App\Domain\MealFit\MealFood;
use App\Domain\Scan\ScanItem;
use App\Domain\Scan\ScannedMeal;

function scanRice(): Food
{
    return new Food('rice', 'White rice', kcal: 130, proteinG: 2.7, fatG: 0.3, carbsG: 28);
}

function scanChicken(): Food
{
    return new Food('chicken', 'Chicken breast', kcal: 165, proteinG: 31, fatG: 3.6, carbsG: 0);
}

function scanBurrito(): Food
{
    return new Food('burrito', 'Burrito bowl', kcal: 150, proteinG: 9, fatG: 5, carbsG: 17);
}

/** rp-clone's plate: rice, chicken, a restaurant burrito bowl and a sauce with no match. */
function scannedPlate(?string $description = null): ScannedMeal
{
    return ScannedMeal::scanned([
        new ScanItem('Cooked rice', 200, scanRice()),
        new ScanItem('Grilled chicken', 150, scanChicken()),
        new ScanItem('Burrito bowl', 100, scanBurrito(), detail: 'Chipotle'),
        new ScanItem('Mystery sauce', 30, null),
    ], $description);
}

/** @param  list<MealFood>  $foods */
function scanHeld(array $foods): array
{
    return array_combine(array_map(fn (MealFood $f) => $f->food->name, $foods), array_map(fn (MealFood $f) => $f->grams, $foods));
}

it('FOOD-043 each food keeps the AI\'s name, its match and its grams, starting at one times', function () {
    $meal = scannedPlate();

    expect(array_map(fn (ScanItem $i) => $i->name, $meal->items))->toBe(['Cooked rice', 'Grilled chicken', 'Burrito bowl', 'Mystery sauce'])
        ->and(array_map(fn (ScanItem $i) => $i->food?->name, $meal->items))->toBe(['White rice', 'Chicken breast', 'Burrito bowl', null])
        ->and(array_map(fn (ScanItem $i) => $meal->grams($i), $meal->items))->toBe([200.0, 150.0, 100.0, 30.0])
        ->and($meal->portion)->toBe(1.0);
});

it('FOOD-043 an estimate of nothing is shown as a tenth of a gram', function () {
    $meal = ScannedMeal::scanned([new ScanItem('Pinch of salt', 0, null), new ScanItem('Rice', 33.333, scanRice())], null);

    expect(array_map(fn (ScanItem $i) => $meal->grams($i), $meal->items))->toBe([0.1, 33.3]);
});

it('FOOD-045 the shown grams follow the portion', function () {
    $meal = ScannedMeal::adjusted(scannedPlate()->items, portion: 1.25, savedMealName: null);

    expect(array_map(fn (ScanItem $i) => $meal->grams($i), $meal->items))->toBe([250.0, 187.5, 125.0, 37.5])
        ->and($meal->portion)->toBe(1.25);
});

it('FOOD-045 totals count the matched rows at the scaled grams', function () {
    $meal = scannedPlate();
    // 200 g rice, 150 g chicken, 100 g burrito bowl; the sauce has no match.
    expect($meal->totals()->kcal)->toEqualWithDelta(657.5, 0.001)
        ->and($meal->totals()->proteinG)->toEqualWithDelta(60.9, 0.001)
        ->and($meal->totals()->fatG)->toEqualWithDelta(11, 0.001)
        ->and($meal->totals()->carbsG)->toEqualWithDelta(73, 0.001);

    $bigger = ScannedMeal::adjusted($meal->items, portion: 1.5, savedMealName: null);

    expect($bigger->totals()->kcal)->toEqualWithDelta(657.5 * 1.5, 0.001)
        ->and($bigger->totals()->carbsG)->toEqualWithDelta(73 * 1.5, 0.001);
});

it('FOOD-045 a row\'s calories follow the meal\'s rule, and a row with no match has none', function () {
    $meal = scannedPlate();

    expect($meal->kcal($meal->items[0]))->toEqualWithDelta(260, 0.001)
        ->and($meal->kcal($meal->items[3]))->toBeNull();
});

it('FOOD-045 the matched foods are the matched rows at their scaled grams, and the sauce is left out', function () {
    $meal = ScannedMeal::adjusted(scannedPlate()->items, portion: 1.5, savedMealName: null);

    expect(scanHeld($meal->matched()))->toBe(['White rice' => 300.0, 'Chicken breast' => 225.0, 'Burrito bowl' => 150.0]);
});

it('FOOD-045 FOOD-046 two rows matched to one food are one food with both amounts', function () {
    $twice = ScannedMeal::scanned([new ScanItem('Rice', 100, scanRice()), new ScanItem('Cooked rice', 50, scanRice())], null);
    $bigger = ScannedMeal::adjusted($twice->items, portion: 1.25, savedMealName: null);

    expect(scanHeld($bigger->matched()))->toBe(['White rice' => 187.5])
        // 150 g of rice at 130 kcal per 100 g.
        ->and($twice->totals()->kcal)->toEqualWithDelta(195, 0.001);
});

it('FOOD-043 rows with no match are counted and the note says they are left out', function () {
    $none = fn (string $name) => new ScanItem($name, 10, null);

    expect(scannedPlate()->unmatchedNote())->toBe('1 food has no match and won\'t be added or saved.')
        ->and(ScannedMeal::scanned([$none('Sauce'), $none('Garnish'), new ScanItem('Rice', 200, scanRice())], null)->unmatchedNote())
        ->toBe('2 foods have no match and won\'t be added or saved.')
        ->and(ScannedMeal::scanned([new ScanItem('Rice', 200, scanRice())], null)->unmatchedNote())->toBeNull();
});

it('FOOD-043 FOOD-044 a scan that finds nothing says so', function () {
    expect(ScannedMeal::scanned([], null)->emptyText())->toBe('No foods found. Try another photo or describe the meal.')
        ->and(scannedPlate()->emptyText())->toBeNull();
});

it('FOOD-046 the saved meal name starts as the typed description, and a photo starts it as Scanned meal', function () {
    expect(scannedPlate('  chicken burrito bowl with rice ')->savedMealName)->toBe('chicken burrito bowl with rice')
        ->and(scannedPlate()->savedMealName)->toBe('Scanned meal')
        ->and(scannedPlate(" \n ")->savedMealName)->toBe('Scanned meal');
});

it('FOOD-046 a long description is cut to the name limit', function () {
    $name = scannedPlate(str_repeat('é', 300))->savedMealName;

    expect(mb_strlen($name))->toBe(255)
        ->and($name)->toBe(str_repeat('é', 255));
});

it('FOOD-046 an adjusted scan keeps the name it was given', function () {
    expect(ScannedMeal::adjusted(scannedPlate()->items, 1.25, 'Burrito night')->savedMealName)->toBe('Burrito night')
        ->and(ScannedMeal::adjusted(scannedPlate()->items, 1.25, null)->savedMealName)->toBe('Scanned meal');
});

it('FOOD-045 the base grams stay unrounded, so the rows follow the portion without drifting', function () {
    $meal = ScannedMeal::scanned([new ScanItem('Rice', 33, scanRice())], null);

    $quarter = ScannedMeal::adjusted($meal->items, portion: 0.25, savedMealName: null);
    $back = ScannedMeal::adjusted($quarter->items, portion: 1.0, savedMealName: null);

    expect($quarter->grams($quarter->items[0]))->toBe(8.3)
        ->and($back->grams($back->items[0]))->toBe(33.0);
});

it('FOOD-080 a restaurant match shows like any other', function () {
    $burrito = scannedPlate()->items[2];

    expect($burrito->food?->name)->toBe('Burrito bowl')
        ->and($burrito->detail)->toBe('Chipotle')
        ->and(scannedPlate()->kcal($burrito))->toEqualWithDelta(150, 0.001);
});
