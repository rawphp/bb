<?php

use App\Domain\Copy\CopyMeal;
use App\Domain\Copy\CopyOptions;
use App\Domain\Copy\CopyPlanner;
use App\Domain\Copy\CopyWrite;
use App\Domain\MealFit\Food;
use App\Domain\MealFit\MealFit;
use App\Domain\MealFit\MealFood;
use App\Domain\MealFit\Weighing;
use App\Domain\Model\MealTarget;
use App\Domain\Recipe\RecipeServing;
use App\Domain\WeekBuilder\MealNames;

// Ported from rp-clone ios/Tests/RPCoreTests/Features/Copy/CopyFoodsTests.swift, CopyDayTests.swift and
// CopyWeekTests.swift (what a copy writes), whose CopyPlanner.swift and TodayModel+Copy.swift decided it on the phone.
// The source is Wednesday's dinner: chicken locked at 200 g, a chili serving locked at 1.5, rice 120 g and oil 10 g.
// Thursday is a rest day (dinner 880 kcal, 68 P, 28 F, 92 C), Friday a moderate one (dinner 960 kcal, 68 P, 28 F, 112 C).

function copyPlanner(): CopyPlanner
{
    return new CopyPlanner(new MealFit);
}

function copyChicken(): Food
{
    return new Food(id: 'chicken', name: 'Chicken breast', kcal: 165, proteinG: 31, fatG: 3.6, carbsG: 0, cookedRatio: 0.75);
}

function copyRice(): Food
{
    return new Food(id: 'rice', name: 'Rice', kcal: 130, proteinG: 2.7, fatG: 0.3, carbsG: 28);
}

function copyOil(): Food
{
    return new Food(id: 'oil', name: 'Olive oil', kcal: 884, proteinG: 0, fatG: 100, carbsG: 0);
}

function copyChili(float $servings, bool $locked): MealFood
{
    return RecipeServing::food('recipe:9', 'Chili', new MealTarget(kcal: 350, proteinG: 33, fatG: 15.5, carbsG: 17), $servings, $locked);
}

/** @return list<MealFood> */
function copyDinner(float $chili = 1.5, bool $chiliLocked = true): array
{
    return [
        new MealFood('food:3', copyChicken(), 200, isLocked: true),
        copyChili($chili, $chiliLocked),
        new MealFood('food:4', copyRice(), 120),
        new MealFood('food:5', copyOil(), 10),
    ];
}

function copyThursdayDinner(): MealTarget
{
    return new MealTarget(kcal: 880, proteinG: 68, fatG: 28, carbsG: 92);
}

function copyFridayDinner(): MealTarget
{
    return new MealTarget(kcal: 960, proteinG: 68, fatG: 28, carbsG: 112);
}

/**
 * @param  list<MealFood>  $entries
 * @return array<string, float> grams by entry id
 */
function copyGrams(array $entries): array
{
    return array_combine(array_map(fn (MealFood $f) => $f->id, $entries), array_map(fn (MealFood $f) => $f->grams, $entries));
}

/**
 * @param  list<MealFood>  $entries
 * @return array<string, bool> locks by entry id
 */
function copyLocks(array $entries): array
{
    return array_combine(array_map(fn (MealFood $f) => $f->id, $entries), array_map(fn (MealFood $f) => $f->isLocked, $entries));
}

/** @param  list<MealFood>  $entries */
function copyMeal(string $id, array $entries = [], ?string $time = '19:30', string $name = 'Dinner', bool $checkedIn = false, ?MealTarget $target = null): CopyMeal
{
    return new CopyMeal($id, $name, $time, $target ?? copyThursdayDinner(), $checkedIn, $entries);
}

/**
 * @param  list<CopyWrite>  $writes
 * @return list<string>
 */
function copyDestinations(array $writes): array
{
    return array_map(fn (CopyWrite $w) => $w->destinationId, $writes);
}

// MARK: the options

it('MEAL-060 the options start at their defaults: re-fit on, times off, locks off', function () {
    $options = new CopyOptions;

    expect($options->updateTargetMacros)->toBeTrue()
        ->and($options->updateMealTimes)->toBeFalse()
        ->and($options->copyLockedState)->toBeFalse();
});

it('MEAL-060 update target macros on re-fits the unlocked foods to each destination', function () {
    // Half a chili serving leaves room for the oil, so no food is fitted to nothing.
    $source = copyDinner(chili: 0.5);
    $thursday = copyPlanner()->entries($source, copyThursdayDinner(), new CopyOptions);
    $friday = copyPlanner()->entries($source, copyFridayDinner(), new CopyOptions);

    expect(copyGrams($thursday))->toBe(copyGrams((new MealFit)->fit($source, copyThursdayDinner())->foods))
        ->and(copyGrams($friday))->toBe(copyGrams((new MealFit)->fit($source, copyFridayDinner())->foods))
        // Each destination has its own targets, so the unlocked foods land on different amounts.
        ->and(copyGrams($thursday))->not->toBe(copyGrams($friday))
        ->and(copyGrams($thursday))->not->toBe(copyGrams($source));
});

it('MEAL-060 update target macros off copies the source grams and servings', function () {
    $entries = copyPlanner()->entries(copyDinner(chili: 1.3, chiliLocked: false), copyThursdayDinner(), new CopyOptions(updateTargetMacros: false));

    expect(copyGrams($entries))->toBe(['food:3' => 200.0, 'recipe:9' => 13.0, 'food:4' => 120.0, 'food:5' => 10.0])
        ->and(RecipeServing::servings($entries[1]))->toBe(1.3);
});

it('MEAL-060 update target macros off copies an unlocked food at 0 g as 0 g: the 1 g floor is the fit\'s', function () {
    $source = [new MealFood('food:3', copyChicken(), 200), new MealFood('food:5', copyOil(), 0)];

    $entries = copyPlanner()->entries($source, copyThursdayDinner(), new CopyOptions(updateTargetMacros: false));

    expect(copyGrams($entries))->toBe(['food:3' => 200.0, 'food:5' => 0.0]);
});

it('MEAL-060 MEAL-005 locked foods keep their grams in every destination, whatever its targets', function () {
    foreach ([copyThursdayDinner(), copyFridayDinner()] as $target) {
        $entries = copyPlanner()->entries(copyDinner(), $target, new CopyOptions);

        expect($entries[0]->grams)->toBe(200.0)
            ->and(RecipeServing::servings($entries[1]))->toBe(1.5);
    }
});

it('MEAL-060 copy locked state off: the copies arrive unlocked, foods and recipe servings', function () {
    $entries = copyPlanner()->entries(copyDinner(), copyThursdayDinner(), new CopyOptions);

    expect(copyLocks($entries))->toBe(['food:3' => false, 'recipe:9' => false, 'food:4' => false, 'food:5' => false]);
});

it('MEAL-060 copy locked state on keeps the source locks', function () {
    $entries = copyPlanner()->entries(copyDinner(), copyThursdayDinner(), new CopyOptions(copyLockedState: true));

    expect(copyLocks($entries))->toBe(['food:3' => true, 'recipe:9' => true, 'food:4' => false, 'food:5' => false]);
});

it('MEAL-060 RCP-002 update target macros on re-fits an unlocked serving to the destination', function () {
    $source = copyDinner(chili: 1, chiliLocked: false);
    $entries = copyPlanner()->entries($source, copyThursdayDinner(), new CopyOptions);
    $expected = RecipeServing::servings((new MealFit)->fit($source, copyThursdayDinner())->foods[1]);

    expect(RecipeServing::servings($entries[1]))->toBe(max($expected, RecipeServing::MIN_SERVINGS))
        ->and($expected)->not->toBe(1.0, 'the destination\'s targets move the serving');
});

it('MEAL-060 a copied serving loses its lock unless copy locked state is on, and keeps its amount through the fit', function () {
    $dropped = copyPlanner()->entries(copyDinner(chili: 1.3), copyThursdayDinner(), new CopyOptions);
    $kept = copyPlanner()->entries(copyDinner(chili: 1.3), copyThursdayDinner(), new CopyOptions(copyLockedState: true));

    expect($dropped[1]->isLocked)->toBeFalse()
        ->and(RecipeServing::servings($dropped[1]))->toBe(1.3)
        ->and($kept[1]->isLocked)->toBeTrue()
        ->and(RecipeServing::servings($kept[1]))->toBe(1.3);
});

it('MEAL-060 the copied list keeps the source order and cooked state', function () {
    $source = [
        new MealFood('food:3', copyChicken(), 150, Weighing::Cooked),
        copyChili(1.5, false),
        new MealFood('food:4', copyRice(), 80),
    ];

    $entries = copyPlanner()->entries($source, copyThursdayDinner(), new CopyOptions(updateTargetMacros: false));

    expect(array_map(fn (MealFood $f) => $f->id, $entries))->toBe(['food:3', 'recipe:9', 'food:4'])
        ->and($entries[0]->weighing)->toBe(Weighing::Cooked)
        ->and($entries[2]->weighing)->toBe(Weighing::Raw);
});

it('MEAL-060 a food the fit leaves at nothing is planned at 1 g, a serving at a tenth', function () {
    // A destination with no fat: the fit takes the oil and the chili to nothing.
    $target = new MealTarget(kcal: 400, proteinG: 60, fatG: 0, carbsG: 30);
    $source = [new MealFood('food:3', copyChicken(), 200), copyChili(1, false), new MealFood('food:5', copyOil(), 10)];
    $fitted = copyGrams((new MealFit)->fit($source, $target)->foods);

    $entries = copyGrams(copyPlanner()->entries($source, $target, new CopyOptions));

    expect($fitted['food:5'])->toBe(0.0)
        ->and($entries['food:5'])->toBe(RecipeServing::MIN_GRAMS)
        ->and($entries['recipe:9'])->toBe(max($fitted['recipe:9'], RecipeServing::MIN_GRAMS));
});

// MARK: which destinations are written

it('MEAL-060 writes each destination the source\'s entries, fitted to the destination\'s own targets', function () {
    $source = copyMeal('wed-3', copyDinner());
    $thursday = copyMeal('thu-3');
    $friday = copyMeal('fri-3', target: copyFridayDinner());

    $writes = copyPlanner()->writes([['source' => $source, 'destination' => $thursday], ['source' => $source, 'destination' => $friday]], new CopyOptions);

    expect(copyDestinations($writes))->toBe(['thu-3', 'fri-3'])
        ->and($writes[0]->sourceId)->toBe('wed-3')
        ->and(copyGrams($writes[0]->entries))->toBe(copyGrams(copyPlanner()->entries(copyDinner(), copyThursdayDinner(), new CopyOptions)))
        ->and(copyGrams($writes[1]->entries))->toBe(copyGrams(copyPlanner()->entries(copyDinner(), copyFridayDinner(), new CopyOptions)));
});

it('MEAL-060 SCH-034 a checked-in destination and the source meal itself are skipped', function () {
    $source = copyMeal('wed-3', copyDinner());

    $writes = copyPlanner()->writes([
        ['source' => $source, 'destination' => copyMeal('wed-2', checkedIn: true)],
        ['source' => $source, 'destination' => $source],
        ['source' => $source, 'destination' => copyMeal('thu-3')],
    ], new CopyOptions);

    expect(copyDestinations($writes))->toBe(['thu-3']);
});

it('MEAL-060 SCH-034 a source with nothing to copy writes nothing, so its destination is not wiped', function () {
    $writes = copyPlanner()->writes([
        ['source' => copyMeal('wed-3'), 'destination' => copyMeal('thu-3', copyDinner())],
        ['source' => copyMeal('wed-1', copyDinner(), name: 'Breakfast'), 'destination' => copyMeal('thu-1')],
    ], new CopyOptions);

    expect(copyDestinations($writes))->toBe(['thu-1']);
});

it('MEAL-060 a destination is written once: the first pair that names it wins', function () {
    $writes = copyPlanner()->writes([
        ['source' => copyMeal('wed-3', copyDinner()), 'destination' => copyMeal('thu-3')],
        ['source' => copyMeal('wed-1', [new MealFood('food:1', copyRice(), 80)]), 'destination' => copyMeal('thu-3')],
    ], new CopyOptions);

    expect(copyDestinations($writes))->toBe(['thu-3'])
        ->and($writes[0]->sourceId)->toBe('wed-3');
});

// MARK: meal times

it('MEAL-060 update meal times off leaves the destination\'s time', function () {
    $writes = copyPlanner()->writes([['source' => copyMeal('wed-3', copyDinner(), '19:30'), 'destination' => copyMeal('thu-1', time: '08:00')]], new CopyOptions);

    expect($writes[0]->time)->toBeNull();
});

it('MEAL-060 update meal times on gives the destination the source\'s time', function () {
    $writes = copyPlanner()->writes([['source' => copyMeal('wed-3', copyDinner(), '19:30'), 'destination' => copyMeal('thu-1', time: '08:00')]], new CopyOptions(updateMealTimes: true));

    expect($writes[0]->time)->toBe('19:30');
});

it('MEAL-060 update meal times keeps a shake\'s workout-relative time, and the shake still gets the foods', function () {
    $source = copyMeal('wed-3', copyDinner(), '19:30');

    $writes = copyPlanner()->writes([
        ['source' => $source, 'destination' => copyMeal('thu-1', time: '08:00', name: 'Breakfast')],
        ['source' => $source, 'destination' => copyMeal('thu-2', time: '12:30', name: MealNames::INTRA_WORKOUT_SHAKE)],
    ], new CopyOptions(updateMealTimes: true));

    expect(copyDestinations($writes))->toBe(['thu-1', 'thu-2'])
        ->and($writes[0]->time)->toBe('19:30')
        ->and($writes[1]->time)->toBeNull();
});

it('MEAL-060 a destination already at the source\'s time, or a source with no time, moves no time', function () {
    $writes = copyPlanner()->writes([
        ['source' => copyMeal('wed-3', copyDinner(), '19:30'), 'destination' => copyMeal('thu-3', time: '19:30')],
        ['source' => copyMeal('wed-1', copyDinner(), null), 'destination' => copyMeal('thu-1', time: '08:00')],
    ], new CopyOptions(updateMealTimes: true));

    expect(array_map(fn (CopyWrite $w) => $w->time, $writes))->toBe([null, null]);
});
