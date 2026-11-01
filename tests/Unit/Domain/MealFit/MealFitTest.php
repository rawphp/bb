<?php

use App\Domain\MealFit\CuratedGroup;
use App\Domain\MealFit\Food;
use App\Domain\MealFit\Macro;
use App\Domain\MealFit\MealFit;
use App\Domain\MealFit\MealFitResult;
use App\Domain\MealFit\MealFood;
use App\Domain\MealFit\Serving;
use App\Domain\MealFit\Shortfall;
use App\Domain\MealFit\Weighing;
use App\Domain\Model\MealFitRules;
use App\Domain\Model\MealTarget;

// Ported from rp-clone ios/Tests/RPCoreTests/Domain/MealFit/MealFitTests.swift. Nutrients per 100 g raw.

function mealFitBeef(): Food
{
    return new Food(
        id: 'beef', name: 'Lean ground beef 95%', kcal: 137, proteinG: 21.4, fatG: 5.0, carbsG: 0,
        cookedRatio: 0.75, servings: [new Serving('4 oz', 113)], curatedGroup: CuratedGroup::ProteinRecommended,
    );
}

function mealFitSourdough(): Food
{
    return new Food(
        id: 'sourdough', name: 'Sourdough bread', kcal: 272, proteinG: 10.8, fatG: 2.4, carbsG: 51.9,
        servings: [new Serving('slice', 40)], curatedGroup: CuratedGroup::CarbsRecommended,
    );
}

function mealFitSalsa(): Food
{
    return new Food(
        id: 'salsa', name: 'Salsa', kcal: 36, proteinG: 1.5, fatG: 0.2, carbsG: 7.0,
        servings: [new Serving('tbsp', 16)], curatedGroup: CuratedGroup::Veggies,
    );
}

function mealFitOil(): Food
{
    return new Food(
        id: 'oil', name: 'Olive oil', kcal: 884, proteinG: 0, fatG: 100, carbsG: 0,
        curatedGroup: CuratedGroup::FatRecommended,
    );
}

/** 150 g beef + 100 g sourdough + 60 g salsa: about P 44, F 10, C 56. */
function mealFitTarget(): MealTarget
{
    return new MealTarget(kcal: 499, proteinG: 44, fatG: 10, carbsG: 56);
}

function mealFitTargetOf(float $kcal, float $protein, float $fat, float $carbs): MealTarget
{
    return new MealTarget(kcal: $kcal, proteinG: $protein, fatG: $fat, carbsG: $carbs);
}

/** A meal food with a fresh id, as Swift's `MealFood(...)` gets a fresh UUID. */
function mealFitFood(Food $food, float $grams, Weighing $weighing = Weighing::Raw, bool $isLocked = false): MealFood
{
    static $next = 0;

    return new MealFood('meal-food-'.++$next, $food, $grams, $weighing, $isLocked);
}

/**
 * @param  list<string>  $locked
 * @return list<MealFood>
 */
function mealFitThreeFoods(array $locked = []): array
{
    return array_map(
        fn (Food $food) => mealFitFood($food, 100, isLocked: in_array($food->name, $locked, true)),
        [mealFitBeef(), mealFitSourdough(), mealFitSalsa()],
    );
}

function mealFitGrams(MealFitResult $result, string $name): float
{
    foreach ($result->foods as $food) {
        if ($food->food->name === $name) {
            return $food->grams;
        }
    }

    throw new LogicException("No {$name} in the meal");
}

/**
 * @param  list<Shortfall>  $shortfalls
 * @return list<Macro>
 */
function mealFitShortMacros(array $shortfalls): array
{
    return array_map(fn (Shortfall $s) => $s->macro, $shortfalls);
}

// MEAL-002

it('MEAL-002 fits beef, sourdough and salsa to targets met', function () {
    $result = (new MealFit)->fit(mealFitThreeFoods(), mealFitTarget());

    expect($result->targetsMet)->toBeTrue()
        ->and($result->shortfalls)->toBe([])
        ->and(mealFitGrams($result, 'Lean ground beef 95%'))->toBeGreaterThan(0)
        ->and(mealFitGrams($result, 'Sourdough bread'))->toBeGreaterThan(0)
        ->and(mealFitGrams($result, 'Salsa'))->toBeGreaterThan(0)
        ->and($result->totals->proteinG)->toEqualWithDelta(44, 5)
        ->and($result->totals->fatG)->toEqualWithDelta(10, 5)
        ->and($result->totals->carbsG)->toEqualWithDelta(56, 5);
});

it('MEAL-002 scales to the target, not a standard serving', function () {
    // 200 g of beef is P 42.8, F 10.0. The "4 oz" serving (113 g) is not used.
    $result = (new MealFit)->fit([mealFitFood(mealFitBeef(), 113)], mealFitTargetOf(274, 42.8, 10, 0));

    expect($result->foods[0]->grams)->toEqualWithDelta(200, 1)
        ->and($result->targetsMet)->toBeTrue();
});

it('MEAL-002 totals include food kcal', function () {
    $foods = [
        mealFitFood(mealFitBeef(), 150),
        mealFitFood(mealFitSourdough(), 100),
        mealFitFood(mealFitSalsa(), 60),
    ];
    $result = (new MealFit)->fit($foods, mealFitTarget());
    // Hand-computed from the fitted grams: sum of food.kcal x grams / 100.
    $expected = array_sum(array_map(fn (MealFood $f) => $f->food->kcal * $f->rawGrams() / 100, $result->foods));

    expect($expected)->toBeGreaterThan(0)
        ->and($result->totals->kcal)->toEqualWithDelta($expected, 1e-9)
        ->and((new MealFit)->totals($foods)->kcal)->toEqualWithDelta(205.5 + 272 + 21.6, 1e-9);
});

it('MEAL-002 food order and ids never change', function () {
    $foods = mealFitThreeFoods();
    $result = (new MealFit)->fit($foods, mealFitTarget());

    expect(array_map(fn (MealFood $f) => $f->id, $result->foods))->toBe(array_map(fn (MealFood $f) => $f->id, $foods))
        ->and(array_map(fn (MealFood $f) => $f->food, $result->foods))->toEqual(array_map(fn (MealFood $f) => $f->food, $foods));
});

it('MEAL-002 redundant foods share the load', function () {
    // Two identical proteins: neither is zeroed.
    $foods = [mealFitFood(mealFitBeef(), 100), mealFitFood(mealFitBeef(), 100)];
    $result = (new MealFit)->fit($foods, mealFitTargetOf(274, 42.8, 10, 0));

    expect($result->foods[0]->grams)->toEqualWithDelta(100, 2)
        ->and($result->foods[1]->grams)->toEqualWithDelta(100, 2)
        ->and($result->targetsMet)->toBeTrue();
});

it('MEAL-002 never goes negative', function () {
    // Far over target: the fit shrinks amounts to zero rather than going negative.
    $result = (new MealFit)->fit([mealFitFood(mealFitSourdough(), 500)], mealFitTargetOf(0, 0, 0, 0));

    expect($result->foods[0]->grams)->toBe(0.0);
});

it('MEAL-002 an empty meal has zero totals', function () {
    $result = (new MealFit)->fit([], mealFitTarget());

    expect($result->foods)->toBe([])
        ->and($result->totals)->toEqual(mealFitTargetOf(0, 0, 0, 0))
        ->and($result->targetsMet)->toBeFalse();
});

// MEAL-003

it('MEAL-003 the tolerance is 5 g', function () {
    expect(MealFit::TOLERANCE)->toBe(5.0);
});

it('MEAL-003 met at exactly 5 g off on each macro', function (float $p, float $f, float $c) {
    $met = (new MealFit)->targetsMet(mealFitTargetOf(0, $p, $f, $c), mealFitTargetOf(0, 40, 20, 60));

    expect($met)->toBeTrue();
})->with([
    [40, 20, 60], [45, 20, 60], [35, 20, 60], [40, 25, 60], [40, 15, 60], [40, 20, 65], [40, 20, 55],
]);

it('MEAL-003 not met just past 5 g off on each macro', function (float $p, float $f, float $c) {
    $met = (new MealFit)->targetsMet(mealFitTargetOf(0, $p, $f, $c), mealFitTargetOf(0, 40, 20, 60));

    expect($met)->toBeFalse();
})->with([
    [45.1, 20, 60], [34.9, 20, 60], [40, 25.1, 60], [40, 14.9, 60], [40, 20, 65.1], [40, 20, 54.9],
]);

it('MEAL-003 a calorie difference does not break the badge', function () {
    expect((new MealFit)->targetsMet(mealFitTargetOf(900, 40, 20, 60), mealFitTargetOf(500, 40, 20, 60)))->toBeTrue();
});

it('MEAL-003 the result reports the badge from the fitted totals', function () {
    // 200 g locked beef is P 42.8, F 10.0, C 0.
    $locked = [mealFitFood(mealFitBeef(), 200, isLocked: true)];
    $near = (new MealFit)->fit($locked, mealFitTargetOf(0, 47.8, 10, 0));
    $far = (new MealFit)->fit($locked, mealFitTargetOf(0, 47.9, 10, 0));

    expect($near->targetsMet)->toBeTrue()
        ->and($far->targetsMet)->toBeFalse();
});

// MEAL-004

it('MEAL-004 the edited food takes the amount and the others rebalance', function () {
    $fit = new MealFit;
    $fitted = $fit->fit(mealFitThreeFoods(), mealFitTarget());
    $result = $fit->setAmount(80, $fitted->foods[1]->id, $fitted->foods, mealFitTarget());

    expect(mealFitGrams($result, 'Sourdough bread'))->toBe(80.0)
        ->and(mealFitGrams($result, 'Salsa'))->not->toBe(mealFitGrams($fitted, 'Salsa'))
        ->and(mealFitGrams($result, 'Salsa'))->toBeGreaterThan(mealFitGrams($fitted, 'Salsa'))
        ->and($result->targetsMet)->toBeTrue();
});

it('MEAL-004 the edited amount is rounded to 1 g', function () {
    $fit = new MealFit;
    $fitted = $fit->fit(mealFitThreeFoods(), mealFitTarget());
    $result = $fit->setAmount(80.4, $fitted->foods[1]->id, $fitted->foods, mealFitTarget());

    expect(mealFitGrams($result, 'Sourdough bread'))->toBe(80.0);
});

it('MEAL-004 editing does not change the lock flag', function () {
    $fit = new MealFit;
    $foods = $fit->fit(mealFitThreeFoods(), mealFitTarget())->foods;
    $unlockedId = $foods[1]->id;
    $afterUnlocked = $fit->setAmount(80, $unlockedId, $foods, mealFitTarget());

    expect($afterUnlocked->foods[1]->isLocked)->toBeFalse();

    $foods[1] = $foods[1]->withLocked(true);
    $afterLocked = $fit->setAmount(90, $unlockedId, $foods, mealFitTarget());

    expect($afterLocked->foods[1]->isLocked)->toBeTrue()
        ->and($afterLocked->foods[1]->grams)->toBe(90.0);
});

it('MEAL-004 a negative amount clamps to zero', function () {
    $foods = mealFitThreeFoods();
    $result = (new MealFit)->setAmount(-5, $foods[1]->id, $foods, mealFitTarget());

    expect(mealFitGrams($result, 'Sourdough bread'))->toBe(0.0);
});

it('MEAL-004 an unknown id changes nothing except the fit', function () {
    $fit = new MealFit;
    $foods = mealFitThreeFoods();

    expect($fit->setAmount(80, 'not-in-the-meal', $foods, mealFitTarget()))
        ->toEqual($fit->fit($foods, mealFitTarget()));
});

// MEAL-005

it('MEAL-005 a locked food keeps its exact amount through a fit', function () {
    $foods = mealFitThreeFoods();
    $foods[1] = $foods[1]->withGrams(123)->withLocked(true);
    $result = (new MealFit)->fit($foods, mealFitTarget());

    expect(mealFitGrams($result, 'Sourdough bread'))->toBe(123.0)
        ->and($result->foods[1]->isLocked)->toBeTrue()
        ->and(mealFitGrams($result, 'Lean ground beef 95%'))->not->toBe(100.0);
});

it('MEAL-005 a locked food survives setting the amount of another food', function () {
    $foods = mealFitThreeFoods();
    $foods[0] = $foods[0]->withGrams(177)->withLocked(true);
    $result = (new MealFit)->setAmount(40, $foods[2]->id, $foods, mealFitTarget());

    expect(mealFitGrams($result, 'Lean ground beef 95%'))->toBe(177.0)
        ->and(mealFitGrams($result, 'Salsa'))->toBe(40.0);
});

it('MEAL-005 a locked cooked food keeps its cooked grams and mode', function () {
    $fit = new MealFit;
    $foods = mealFitThreeFoods();
    $foods[0] = mealFitFood(mealFitBeef(), 120, Weighing::Cooked, isLocked: true);
    $fitted = $fit->fit($foods, mealFitTarget());
    $edited = $fit->setAmount(70, $foods[1]->id, $foods, mealFitTarget());

    foreach ([$fitted, $edited] as $result) {
        expect($result->foods[0]->grams)->toBe(120.0)
            ->and($result->foods[0]->weighing)->toBe(Weighing::Cooked)
            ->and($result->foods[0]->rawGrams())->toEqualWithDelta(160, 1e-9);
    }
});

it('MEAL-005 locked foods count towards the totals', function () {
    // Locked beef already covers the protein and fat; the rest hunts carbs.
    $fit = new MealFit;
    $foods = mealFitThreeFoods();
    $foods[0] = $foods[0]->withGrams(200)->withLocked(true);
    $result = $fit->fit($foods, mealFitTargetOf(0, 48, 10, 30));

    // Ignoring the locked beef, the free foods would need ~48 g protein from bread and salsa alone.
    expect(mealFitGrams($result, 'Lean ground beef 95%'))->toBe(200.0)
        ->and($result->targetsMet)->toBeTrue()
        ->and($result->totals->proteinG)->toEqualWithDelta(48, MealFit::TOLERANCE)
        ->and($result->totals->fatG)->toEqualWithDelta(10, MealFit::TOLERANCE)
        ->and($result->totals->carbsG)->toEqualWithDelta(30, MealFit::TOLERANCE);

    // The free foods supply roughly target minus locked: about 5 g protein, 30 g carbs.
    $free = $fit->totals(array_values(array_filter($result->foods, fn (MealFood $f) => ! $f->isLocked)));

    expect($free->proteinG)->toEqualWithDelta(48 - 42.8, MealFit::TOLERANCE)
        ->and($free->carbsG)->toEqualWithDelta(30, MealFit::TOLERANCE);
});

it('MEAL-005 everything locked means no change', function () {
    $foods = array_map(fn (MealFood $f) => $f->withLocked(true), mealFitThreeFoods());
    $result = (new MealFit)->fit($foods, mealFitTarget());

    expect($result->foods)->toEqual($foods)
        ->and($result->targetsMet)->toBeFalse();
});

// MEAL-006

it('MEAL-006 rebalance restores targets met and keeps locks', function () {
    $fit = new MealFit;
    $good = $fit->fit(mealFitThreeFoods(), mealFitTarget());

    expect($good->targetsMet)->toBeTrue();

    // The user knocks the amounts off; bread is locked at its fitted amount.
    $knocked = $good->foods;
    $knocked[0] = $knocked[0]->withGrams(300);
    $knocked[1] = $knocked[1]->withLocked(true);
    $knocked[2] = $knocked[2]->withGrams(5);

    expect($fit->targetsMet($fit->totals($knocked), mealFitTarget()))->toBeFalse();

    $result = $fit->fit($knocked, mealFitTarget());

    expect($result->targetsMet)->toBeTrue()
        ->and($result->foods[1]->grams)->toBe($good->foods[1]->grams)
        ->and($result->foods[0]->grams)->not->toBe(300.0)
        ->and($result->foods[2]->grams)->not->toBe(5.0);
});

it('MEAL-006 rebalance is stable', function () {
    $fit = new MealFit;
    $once = $fit->fit(mealFitThreeFoods(), mealFitTarget());

    expect($fit->fit($once->foods, mealFitTarget()))->toEqual($once);
});

// MEAL-007

it('MEAL-007 no carb source reports carbs and the group', function () {
    $foods = [mealFitFood(mealFitBeef(), 100), mealFitFood(mealFitOil(), 5)];
    $result = (new MealFit)->fit($foods, mealFitTarget());

    expect($result->targetsMet)->toBeFalse()
        ->and($result->shortfalls)->toHaveCount(1)
        ->and($result->shortfalls[0]->macro)->toBe(Macro::Carbs)
        ->and($result->shortfalls[0]->group)->toBe(CuratedGroup::CarbsRecommended)
        ->and($result->shortfalls[0]->shortG)->toEqualWithDelta(56 - $result->totals->carbsG, 1e-9)
        ->and($result->shortfalls[0]->shortG)->toEqualWithDelta(56, 0.01);
});

it('MEAL-007 never adds a food', function () {
    $foods = [mealFitFood(mealFitBeef(), 100), mealFitFood(mealFitOil(), 5)];
    $result = (new MealFit)->fit($foods, mealFitTarget());

    expect(array_map(fn (MealFood $f) => $f->id, $result->foods))->toBe(array_map(fn (MealFood $f) => $f->id, $foods))
        ->and($result->foods)->toHaveCount(2);
});

it('MEAL-007 no shortfall when the targets are met', function () {
    expect((new MealFit)->fit(mealFitThreeFoods(), mealFitTarget())->shortfalls)->toBe([]);
});

it('MEAL-007 several shortfalls come in protein, fat, carbs order', function () {
    // Only salsa: it can carry carbs, but protein and fat have no source.
    $result = (new MealFit)->fit([mealFitFood(mealFitSalsa(), 100)], mealFitTarget());

    expect(mealFitShortMacros($result->shortfalls))->toBe([Macro::Protein, Macro::Fat])
        ->and(array_map(fn (Shortfall $s) => $s->group, $result->shortfalls))
        ->toBe([CuratedGroup::ProteinRecommended, CuratedGroup::FatRecommended]);
});

it('MEAL-007 locked foods can cause a shortfall', function () {
    // Locked bread covers 20.8 g of carbs against 56: carbs fall short and nothing unlocked has any.
    $foods = [
        mealFitFood(mealFitBeef(), 100),
        mealFitFood(mealFitSourdough(), 40, isLocked: true),
    ];
    $result = (new MealFit)->fit($foods, mealFitTargetOf(0, 30, 10, 56));

    expect(mealFitShortMacros($result->shortfalls))->toBe([Macro::Carbs])
        ->and($result->shortfalls[0]->shortG)->toEqualWithDelta(56 - 20.76, 0.01);
});

it('MEAL-007 an overshoot is not a shortfall', function () {
    // Locked 300 g bread is far over the carb target: not met, but nothing is "short".
    $foods = [mealFitFood(mealFitSourdough(), 300, isLocked: true)];
    $result = (new MealFit)->fit($foods, mealFitTargetOf(0, 30, 5, 50));

    expect($result->targetsMet)->toBeFalse()
        ->and(mealFitShortMacros($result->shortfalls))->not->toContain(Macro::Carbs);
});

it('MEAL-007 an empty meal is short of everything', function () {
    $result = (new MealFit)->fit([], mealFitTarget());

    expect(mealFitShortMacros($result->shortfalls))->toBe([Macro::Protein, Macro::Fat, Macro::Carbs]);
});

// FOOD-006

it('FOOD-006 raw and cooked give identical macros', function () {
    $raw = mealFitFood(mealFitBeef(), 200, Weighing::Raw);
    $cooked = mealFitFood(mealFitBeef(), 150, Weighing::Cooked);

    expect($raw->rawGrams())->toEqualWithDelta(200, 1e-9)
        ->and($cooked->rawGrams())->toEqualWithDelta(200, 1e-9)
        ->and($raw->macros()->kcal)->toEqualWithDelta(274, 1e-9)
        ->and($cooked->macros()->kcal)->toEqualWithDelta($raw->macros()->kcal, 1e-9)
        ->and($cooked->macros()->proteinG)->toEqualWithDelta($raw->macros()->proteinG, 1e-9)
        ->and($cooked->macros()->proteinG)->toEqualWithDelta(42.8, 1e-9)
        ->and($cooked->macros()->fatG)->toEqualWithDelta($raw->macros()->fatG, 1e-9)
        ->and($cooked->macros()->carbsG)->toEqualWithDelta($raw->macros()->carbsG, 1e-9);
});

it('FOOD-006 a fit in cooked mode matches raw mode within rounding', function () {
    $fit = new MealFit;
    $target = mealFitTargetOf(274, 42.8, 10, 0);
    $rawFit = $fit->fit([mealFitFood(mealFitBeef(), 100, Weighing::Raw)], $target);
    $cookedFit = $fit->fit([mealFitFood(mealFitBeef(), 100, Weighing::Cooked)], $target);

    expect($rawFit->foods[0]->grams)->toEqualWithDelta(200, 1)
        ->and($cookedFit->foods[0]->grams)->toEqualWithDelta(150, 1)
        ->and($cookedFit->foods[0]->weighing)->toBe(Weighing::Cooked)
        ->and($cookedFit->totals->proteinG)->toEqualWithDelta($rawFit->totals->proteinG, 0.5)
        ->and($cookedFit->totals->fatG)->toEqualWithDelta($rawFit->totals->fatG, 0.5)
        ->and($cookedFit->targetsMet)->toBeTrue();
});

it('FOOD-006 toggling keeps the macros and rounds to 1 g', function () {
    $raw = mealFitFood(mealFitBeef(), 200, Weighing::Raw, isLocked: true);
    $cooked = $raw->weighedAs(Weighing::Cooked);

    expect($cooked->grams)->toBe(150.0)
        ->and($cooked->weighing)->toBe(Weighing::Cooked)
        ->and($cooked->id)->toBe($raw->id)
        ->and($cooked->isLocked)->toBeTrue()
        ->and($cooked->macros()->proteinG)->toEqualWithDelta($raw->macros()->proteinG, 1e-9)
        ->and($cooked->weighedAs(Weighing::Raw)->grams)->toBe(200.0)
        // 101 g raw is 75.75 g cooked: rounds to 76.
        ->and(mealFitFood(mealFitBeef(), 101)->weighedAs(Weighing::Cooked)->grams)->toBe(76.0);
});

it('FOOD-006 a food without a cooked ratio treats cooked as raw', function () {
    $bread = mealFitFood(mealFitSourdough(), 100, Weighing::Cooked);

    expect($bread->rawGrams())->toBe(100.0)
        ->and($bread->macros()->carbsG)->toEqualWithDelta(51.9, 1e-9)
        ->and($bread->weighedAs(Weighing::Raw)->grams)->toBe(100.0);
});

// Rounding and household units (FOOD-004, FOOD-005)

it('MEAL-002 every fitted amount is a whole number of grams', function () {
    $fit = new MealFit;
    $results = [
        $fit->fit(mealFitThreeFoods(), mealFitTarget()),
        $fit->fit(mealFitThreeFoods(), mealFitTargetOf(700, 51.3, 13.7, 77.7)),
        $fit->fit(
            [mealFitFood(mealFitBeef(), 100, Weighing::Cooked), mealFitFood(mealFitSourdough(), 100)],
            mealFitTarget(),
        ),
    ];

    foreach ($results as $result) {
        foreach ($result->foods as $f) {
            expect($f->grams)->toBe(round($f->grams), "{$f->food->name} is {$f->grams} g");
        }
    }
});

it('FOOD-005 the household count is rounded to a tenth', function () {
    // 105 g of a 40 g slice is 2.625 slices.
    $household = mealFitFood(mealFitSourdough(), 105)->household();

    expect($household?->label)->toBe('slice')
        ->and($household?->count)->toEqualWithDelta(2.6, 1e-9);
});

it('FOOD-005 the household amount uses the first serving', function () {
    $loaf = new Food(
        id: 'loaf', name: 'Sourdough loaf', kcal: 272, proteinG: 10.8, fatG: 2.4, carbsG: 51.9,
        servings: [new Serving('slice', 40), new Serving('loaf', 800)],
        curatedGroup: CuratedGroup::CarbsRecommended,
    );
    $household = mealFitFood($loaf, 105)->household();

    expect($household?->label)->toBe('slice')
        ->and($household?->count)->toEqualWithDelta(2.6, 1e-9);
});

it('FOOD-005 the household amount uses raw grams for cooked foods', function () {
    // 150 g cooked beef is 200 g raw; a "4 oz" serving is 113 g raw: 1.77 -> 1.8.
    $household = mealFitFood(mealFitBeef(), 150, Weighing::Cooked)->household();

    expect($household?->label)->toBe('4 oz')
        ->and($household?->count)->toEqualWithDelta(1.8, 1e-9);
});

it('FOOD-005 no servings means no household amount', function () {
    expect(mealFitFood(mealFitOil(), 10)->household())->toBeNull();
});

it('MEAL-003 MealFit is the MealFitRules that Model checks a meal against', function () {
    expect(new MealFit)->toBeInstanceOf(MealFitRules::class);
});
