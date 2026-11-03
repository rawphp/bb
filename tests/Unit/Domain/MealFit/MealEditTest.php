<?php

use App\Domain\MealFit\Food;
use App\Domain\MealFit\MealEdit;
use App\Domain\MealFit\MealFit;
use App\Domain\MealFit\MealFood;
use App\Domain\MealFit\Serving;
use App\Domain\MealFit\Weighing;
use App\Domain\Model\MealTarget;
use App\Domain\Pantry\FoodUnit;
use App\Domain\Recipe\RecipeServing;

// Ported from rp-clone ios/Tests/RPCoreTests/Features/Meal: the edit cases of MealModelTests (MEAL-002 to MEAL-008),
// MealRecipeTests (RCP-002) and the floors of MealSaveTests. The fit itself is MealFitTest's; these prove what an
// edit does to the list around it. Dinner is 1040 kcal, 68 P, 28 F, 128 C; the stew is 355 kcal, 38.5 P, 3.5 F, 39 C a serving.

function mealEditDinner(): MealTarget
{
    return new MealTarget(kcal: 1040, proteinG: 68, fatG: 28, carbsG: 128);
}

function mealEditChicken(float $grams = 0, bool $locked = false, Weighing $weighing = Weighing::Raw): MealFood
{
    $food = new Food(id: 'chicken-food', name: 'Chicken breast', kcal: 165, proteinG: 31, fatG: 3.6, carbsG: 0, cookedRatio: 0.75, servings: [new Serving('fillet', 150)]);

    return new MealFood('chicken', $food, $grams, $weighing, $locked);
}

function mealEditRice(float $grams = 0, bool $locked = false): MealFood
{
    $food = new Food(id: 'rice-food', name: 'Rice', kcal: 130, proteinG: 2.7, fatG: 0.3, carbsG: 28, servings: [new Serving('cup', 185)]);

    return new MealFood('rice', $food, $grams, isLocked: $locked);
}

function mealEditOil(float $grams = 0, bool $locked = false): MealFood
{
    $food = new Food(id: 'oil-food', name: 'Olive oil', kcal: 884, proteinG: 0, fatG: 100, carbsG: 0);

    return new MealFood('oil', $food, $grams, isLocked: $locked);
}

function mealEditOats(float $grams = 0): MealFood
{
    return new MealFood('oats', new Food(id: 'oats-food', name: 'Oats', kcal: 389, proteinG: 16.9, fatG: 6.9, carbsG: 66.3), $grams);
}

function mealEditStew(float $servings = 0, bool $locked = false): MealFood
{
    return RecipeServing::food('stew', 'Stew', new MealTarget(kcal: 355, proteinG: 38.5, fatG: 3.5, carbsG: 39), $servings, $locked);
}

/**
 * @param  list<MealFood>  $meal
 * @return array<string, float> grams by entry id
 */
function mealEditGrams(array $meal): array
{
    return array_combine(array_map(fn (MealFood $f) => $f->id, $meal), array_map(fn (MealFood $f) => $f->grams, $meal));
}

/**
 * @param  list<MealFood>  $meal
 * @return list<string>
 */
function mealEditIds(array $meal): array
{
    return array_map(fn (MealFood $f) => $f->id, $meal);
}

function mealEdit(): MealEdit
{
    return new MealEdit(new MealFit);
}

// MEAL-002

it('MEAL-002 fits the whole meal to its targets when foods join it, in the meal\'s order', function () {
    $fitted = mealEdit()->add([], [mealEditChicken(), mealEditRice(), mealEditOil()], mealEditDinner());
    $expected = (new MealFit)->fit([mealEditChicken(), mealEditRice(), mealEditOil()], mealEditDinner());

    expect(mealEditIds($fitted))->toBe(['chicken', 'rice', 'oil'])
        ->and(mealEditGrams($fitted))->toBe(mealEditGrams($expected->foods))
        ->and((new MealFit)->targetsMet((new MealFit)->totals($fitted), mealEditDinner()))->toBeTrue();
});

it('MEAL-002 fits the foods already in the meal with the new ones', function () {
    $lunch = new MealTarget(kcal: 780, proteinG: 51, fatG: 21, carbsG: 96);
    $meal = [mealEditChicken(150, weighing: Weighing::Cooked), mealEditRice(80)];

    $fitted = mealEdit()->add($meal, [mealEditOil()], $lunch);
    $expected = (new MealFit)->fit([...$meal, mealEditOil()], $lunch);

    expect(mealEditIds($fitted))->toBe(['chicken', 'rice', 'oil'])
        ->and(mealEditGrams($fitted))->toBe(mealEditGrams($expected->foods))
        ->and($fitted[0]->weighing)->toBe(Weighing::Cooked);
});

it('MEAL-002 MEAL-005 keeps a locked food at its exact grams when foods join', function () {
    $fitted = mealEdit()->add([mealEditRice(80, locked: true), mealEditChicken(150)], [mealEditOil()], mealEditDinner());

    expect($fitted[0]->grams)->toBe(80.0)
        ->and($fitted[0]->isLocked)->toBeTrue();
});

it('MEAL-002 joins a food at the grams it came with when it is locked, as a scanned food is (FOOD-045)', function () {
    $scanned = mealEditOats(55)->withLocked(true);

    $fitted = mealEdit()->add([mealEditChicken(), mealEditRice()], [$scanned], mealEditDinner());

    expect($fitted[2]->grams)->toBe(55.0)
        ->and($fitted[2]->isLocked)->toBeTrue()
        ->and($fitted[0]->grams)->toBeGreaterThan(0.0);
});

it('MEAL-002 changes nothing but the new foods\' amounts when nothing joins an empty meal', function () {
    expect(mealEdit()->add([], [], mealEditDinner()))->toBe([]);
});

// RCP-002 scaling

it('RCP-002 scales a recipe serving with the foods to hit the targets, to a tenth of a serving', function () {
    $fitted = mealEdit()->add([], [mealEditChicken(), mealEditRice(), mealEditOil(), mealEditStew()], mealEditDinner());
    $expected = (new MealFit)->fit([mealEditChicken(), mealEditRice(), mealEditOil(), mealEditStew()], mealEditDinner());
    $servings = RecipeServing::servings($fitted[3]);

    expect(mealEditGrams($fitted))->toBe(mealEditGrams($expected->foods))
        ->and(round($servings * 10))->toEqualWithDelta($servings * 10, 1e-9)
        ->and((new MealFit)->targetsMet((new MealFit)->totals($fitted), mealEditDinner()))->toBeTrue();
});

it('RCP-002 fits a serving already in the meal with a new food', function () {
    $fitted = mealEdit()->add([mealEditStew(1)], [mealEditRice(), mealEditOil()], mealEditDinner());
    $expected = (new MealFit)->fit([mealEditStew(1), mealEditRice(), mealEditOil()], mealEditDinner());

    expect(mealEditGrams($fitted))->toBe(mealEditGrams($expected->foods));
});

it('RCP-002 keeps a locked serving at its servings when foods join and through a rebalance', function () {
    $joined = mealEdit()->add([mealEditStew(1.5, locked: true)], [mealEditRice(), mealEditOil()], mealEditDinner());
    $rebalanced = mealEdit()->rebalance([mealEditRice(20), mealEditStew(0.7, locked: true)], mealEditDinner());

    expect(RecipeServing::servings($joined[0]))->toBe(1.5)
        ->and(RecipeServing::servings($rebalanced[1]))->toBe(0.7)
        ->and($rebalanced[1]->isLocked)->toBeTrue();
});

// MEAL-004

it('MEAL-004 adds five grams to a food with + and the other foods rebalance', function () {
    $meal = mealEdit()->add([], [mealEditChicken(), mealEditRice(), mealEditOil()], mealEditDinner());

    $next = mealEdit()->step($meal, 'chicken', up: true, isServing: false, target: mealEditDinner());
    $expected = (new MealFit)->setAmount($meal[0]->grams + MealEdit::STEP_GRAMS, 'chicken', $meal, mealEditDinner());

    expect($next[0]->grams)->toBe($meal[0]->grams + 5)
        ->and(mealEditGrams($next))->toBe(mealEditGrams($expected->foods))
        ->and(mealEditIds($next))->toBe(['chicken', 'rice', 'oil']);
});

it('MEAL-004 takes five grams off with − and never goes below zero', function () {
    $meal = [mealEditRice(80), mealEditChicken(150)];

    $down = mealEdit()->step($meal, 'rice', up: false, isServing: false, target: mealEditDinner());
    $three = mealEdit()->step([mealEditRice(3), mealEditChicken(150)], 'rice', up: false, isServing: false, target: mealEditDinner());

    expect($down[0]->grams)->toBe(75.0)
        ->and($three[0]->grams)->toBe(0.0);
});

it('MEAL-004 changes nothing when − is pressed on a food already at zero', function () {
    $meal = [mealEditRice(0), mealEditChicken(150)];

    expect(mealEdit()->step($meal, 'rice', up: false, isServing: false, target: mealEditDinner()))->toEqual($meal);
});

it('MEAL-004 sets typed grams on a food and the others move', function () {
    $meal = mealEdit()->add([], [mealEditChicken(), mealEditRice(), mealEditOil()], mealEditDinner());

    $next = mealEdit()->setAmount($meal, 'chicken', $meal[0]->grams + 100, mealEditDinner());

    // 100 g more chicken is 3.6 g more fat, which the oil gives back.
    expect($next[0]->grams)->toBe($meal[0]->grams + 100)
        ->and($next[2]->grams)->toBeLessThan($meal[2]->grams);
});

it('MEAL-004 holds the edited food at zero when zero is typed', function () {
    $next = mealEdit()->setAmount([mealEditRice(80), mealEditChicken(150)], 'rice', 0, mealEditDinner());

    expect($next[0]->grams)->toBe(0.0);
});

it('MEAL-004 ignores an edit of a food that is not in the meal', function () {
    $meal = [mealEditRice(80), mealEditChicken(150)];

    expect(mealEdit()->setAmount($meal, 'gone', 40, mealEditDinner()))->toBe($meal)
        ->and(mealEdit()->step($meal, 'gone', up: true, isServing: false, target: mealEditDinner()))->toBe($meal);
});

it('MEAL-004 turns a typed amount into grams: grams, ounces and the first household unit', function () {
    $rice = mealEditRice(80);

    expect(mealEdit()->gramsFor($rice, false, 120, 'g'))->toBe(120.0)
        ->and(mealEdit()->gramsFor($rice, false, 2, 'oz'))->toEqualWithDelta(2 * FoodUnit::GRAMS_PER_OUNCE, 1e-9)
        // Two cups of 185 g.
        ->and(mealEdit()->gramsFor($rice, false, 2, 'household'))->toBe(370.0);
});

it('MEAL-004 counts a cooked food\'s household units as raw grams and shows cooked grams', function () {
    $chicken = mealEditChicken(150, weighing: Weighing::Cooked);

    // One fillet is 150 g raw, 112.5 g cooked.
    expect(mealEdit()->gramsFor($chicken, false, 1, 'household'))->toBe(112.5)
        ->and(mealEdit()->setAmount([$chicken], 'chicken', 112.5, mealEditDinner())[0]->grams)->toBe(113.0);
});

it('MEAL-004 offers a food with no household unit none', function () {
    expect(mealEdit()->gramsFor(mealEditOil(5), false, 2, 'household'))->toBeNull()
        ->and(mealEdit()->gramsFor(mealEditOats(40), false, 2, 'household'))->toBeNull();
});

it('MEAL-004 RCP-002 refuses a unit that does not suit the entry', function () {
    expect(mealEdit()->gramsFor(mealEditRice(80), false, 2, 'servings'))->toBeNull()
        ->and(mealEdit()->gramsFor(mealEditStew(1), true, 200, 'g'))->toBeNull()
        ->and(mealEdit()->gramsFor(mealEditStew(1), true, 2, 'oz'))->toBeNull()
        ->and(mealEdit()->gramsFor(mealEditStew(1), true, 2, 'household'))->toBeNull()
        ->and(mealEdit()->gramsFor(mealEditRice(80), false, 2, 'cups'))->toBeNull();
});

// RCP-002 + and − and typed servings

it('RCP-002 adds half a serving with + and the foods rebalance', function () {
    $meal = [mealEditRice(100), mealEditStew(1)];

    $next = mealEdit()->step($meal, 'stew', up: true, isServing: true, target: mealEditDinner());
    $expected = (new MealFit)->setAmount(15, 'stew', $meal, mealEditDinner());

    expect(MealEdit::STEP_SERVINGS)->toBe(0.5)
        ->and(RecipeServing::servings($next[1]))->toBe(1.5)
        ->and($next[0]->grams)->toBe($expected->foods[0]->grams);
});

it('RCP-002 takes half a serving off with −', function () {
    $next = mealEdit()->step([mealEditStew(1)], 'stew', up: false, isServing: true, target: mealEditDinner());

    expect(RecipeServing::servings($next[0]))->toBe(0.5);
});

it('RCP-002 never takes a serving below a tenth with −, and changes nothing at a tenth', function () {
    $low = mealEdit()->step([mealEditStew(0.3)], 'stew', up: false, isServing: true, target: mealEditDinner());
    $meal = [mealEditRice(50), mealEditStew(0.1)];

    expect(RecipeServing::servings($low[0]))->toBe(0.1)
        ->and(mealEdit()->step($meal, 'stew', up: false, isServing: true, target: mealEditDinner()))->toBe($meal);
});

it('RCP-002 turns typed servings into grams, at least a tenth of a serving', function () {
    $stew = mealEditStew(1);

    expect(mealEdit()->gramsFor($stew, true, 2.5, 'servings'))->toBe(25.0)
        ->and(mealEdit()->gramsFor($stew, true, 0, 'servings'))->toBe(RecipeServing::MIN_GRAMS)
        ->and(mealEdit()->gramsFor($stew, true, 0.04, 'servings'))->toBe(RecipeServing::MIN_GRAMS);
});

// MEAL-005

it('MEAL-005 flips the lock and nothing else, without a refit', function () {
    $meal = [mealEditChicken(150), mealEditStew(1.5, locked: true), mealEditRice(80)];

    $locked = mealEdit()->lock($meal, 'rice', true);
    $unlocked = mealEdit()->lock($meal, 'stew', false);

    expect($locked[2]->isLocked)->toBeTrue()
        ->and(mealEditGrams($locked))->toBe(mealEditGrams($meal))
        ->and($unlocked[1]->isLocked)->toBeFalse()
        ->and(mealEditGrams($unlocked))->toBe(mealEditGrams($meal))
        ->and(mealEditIds($locked))->toBe(['chicken', 'stew', 'rice']);
});

it('MEAL-005 keeps a locked food at its exact grams through another food\'s edit, a step and a rebalance', function () {
    $meal = mealEdit()->add([], [mealEditChicken(), mealEditRice(), mealEditOil()], mealEditDinner());
    $meal = mealEdit()->lock($meal, 'oil', true);
    $oil = $meal[2]->grams;

    $typed = mealEdit()->setAmount($meal, 'rice', $meal[1]->grams + 40, mealEditDinner());
    $stepped = mealEdit()->step($typed, 'chicken', up: true, isServing: false, target: mealEditDinner());
    $rebalanced = mealEdit()->rebalance($stepped, mealEditDinner());

    expect($typed[2]->grams)->toBe($oil)
        ->and($stepped[2]->grams)->toBe($oil)
        ->and($rebalanced[2]->grams)->toBe($oil)
        ->and($rebalanced[2]->isLocked)->toBeTrue();
});

it('MEAL-005 frees an unlocked food for the next edit again', function () {
    $meal = mealEdit()->add([], [mealEditChicken(), mealEditRice(), mealEditOil()], mealEditDinner());
    $meal = mealEdit()->lock(mealEdit()->lock($meal, 'rice', true), 'rice', false);

    $next = mealEdit()->setAmount($meal, 'chicken', $meal[0]->grams + 60, mealEditDinner());

    expect($next[1]->isLocked)->toBeFalse()
        ->and($next[1]->grams)->not->toBe($meal[1]->grams);
});

it('MEAL-005 holds the edited food even when it is locked, and leaves its lock alone', function () {
    $meal = [mealEditRice(80, locked: true), mealEditChicken(150)];

    $next = mealEdit()->setAmount($meal, 'rice', 120, mealEditDinner());

    expect($next[0]->grams)->toBe(120.0)
        ->and($next[0]->isLocked)->toBeTrue();
});

// MEAL-006

it('MEAL-006 fits every unlocked food to the meal\'s target again', function () {
    $lunch = new MealTarget(kcal: 780, proteinG: 51, fatG: 21, carbsG: 96);
    $meal = [mealEditOats(40), new MealFood('whey', new Food(id: 'whey-food', name: 'Whey', kcal: 400, proteinG: 80, fatG: 6, carbsG: 8), 30)];

    $next = mealEdit()->rebalance($meal, $lunch);
    $expected = (new MealFit)->fit($meal, $lunch);

    expect(mealEditGrams($next))->toBe(mealEditGrams($expected->foods))
        ->and(mealEditIds($next))->toBe(['oats', 'whey']);
});

it('MEAL-006 leaves an empty meal empty and a locked food as it was', function () {
    $meal = [mealEditChicken(150, locked: true), mealEditRice(80)];

    expect(mealEdit()->rebalance([], mealEditDinner()))->toBe([])
        ->and(mealEdit()->rebalance($meal, mealEditDinner())[0]->grams)->toBe(150.0);
});

// MEAL-008

it('MEAL-008 removes a food and refits nothing, adds nothing', function () {
    $meal = mealEdit()->add([], [mealEditChicken(), mealEditRice(), mealEditOil()], mealEditDinner());

    $next = mealEdit()->remove($meal, 'rice');

    expect(mealEditIds($next))->toBe(['chicken', 'oil'])
        ->and(mealEditGrams($next))->toBe(['chicken' => $meal[0]->grams, 'oil' => $meal[2]->grams]);
});

it('MEAL-008 RCP-002 removes a serving and leaves the foods alone', function () {
    $meal = [mealEditChicken(150), mealEditStew(1.5, locked: true), mealEditRice(80)];

    $next = mealEdit()->remove($meal, 'stew');

    expect(mealEditIds($next))->toBe(['chicken', 'rice'])
        ->and(mealEditGrams($next))->toBe(['chicken' => 150.0, 'rice' => 80.0]);
});

it('MEAL-008 leaves an empty meal when the last food goes, and ignores an unknown one', function () {
    $meal = [mealEditRice(100)];

    expect(mealEdit()->remove($meal, 'rice'))->toBe([])
        ->and(mealEdit()->remove($meal, 'gone'))->toEqual($meal);
});

// Saved floor

it('MEAL-002 MEAL-004 RCP-002 saves a food fitted or typed to nothing as 1 g and a serving as a tenth', function () {
    // 300 g of locked rice already overshoots carbs, so the fit gives the stew and the banana nothing.
    $banana = new MealFood('banana', new Food(id: 'banana-food', name: 'Banana', kcal: 89, proteinG: 1.1, fatG: 0.3, carbsG: 23), 0);
    $meal = mealEdit()->add([mealEditRice(300, locked: true)], [$banana, mealEditStew()], mealEditDinner());
    $planned = mealEdit()->planned($meal);

    expect($meal[1]->grams)->toBe(0.0)
        ->and($meal[2]->grams)->toBe(0.0)
        ->and($planned[1]->grams)->toBe(1.0)
        ->and(RecipeServing::servings($planned[2]))->toBe(0.1)
        ->and($planned[0]->grams)->toBe(300.0)
        ->and(mealEdit()->planned([mealEditRice(0)])[0]->grams)->toBe(RecipeServing::MIN_GRAMS);
});

it('MEAL-002 saves amounts at or above 1 g exactly as they are', function () {
    $meal = [mealEditRice(1), mealEditChicken(150.4, locked: true), mealEditStew(1.5)];

    expect(mealEditGrams(mealEdit()->planned($meal)))->toBe(mealEditGrams($meal));
});
