<?php

use App\Capabilities\Reads\Nutrition\BasketPayload;
use App\Domain\MealFit\Food;
use App\Domain\MealFit\MealFood;
use App\Domain\MealFit\Serving;
use App\Domain\MealFit\Weighing;
use App\Domain\Model\MealTarget;
use App\Domain\Recipe\RecipeServing;

// FOOD-030: what Add would make of the Pantry's basket, from in-memory foods. Dinner is 1040 kcal, 68 P, 28 F, 128 C.

function basketDinner(): MealTarget
{
    return new MealTarget(kcal: 1040, proteinG: 68, fatG: 28, carbsG: 128);
}

function basketChicken(string $id, float $grams = 0, Weighing $weighing = Weighing::Raw, bool $locked = false): MealFood
{
    $food = new Food(id: 'chicken-food', name: 'Chicken breast', kcal: 165, proteinG: 31, fatG: 3.6, carbsG: 0, cookedRatio: 0.75, servings: [new Serving('fillet', 150)]);

    return new MealFood($id, $food, $grams, $weighing, $locked);
}

function basketRice(string $id, float $grams = 0): MealFood
{
    return new MealFood($id, new Food(id: 'rice-food', name: 'Rice', kcal: 130, proteinG: 2.7, fatG: 0.3, carbsG: 28), $grams);
}

function basketOil(string $id, float $grams = 0): MealFood
{
    return new MealFood($id, new Food(id: 'oil-food', name: 'Olive oil', kcal: 884, proteinG: 0, fatG: 100, carbsG: 0), $grams);
}

/** @return array<string, mixed> */
function basketShow(array $meal, array $picks): array
{
    return BasketPayload::build($meal, $picks, basketDinner())->toArray();
}

it('FOOD-030 gives each pick the amount the fit gives it when it joins the meal, in the order sent', function () {
    $show = basketShow([], [
        ['id' => 'a', 'fit' => basketChicken('a')],
        ['id' => 'b', 'fit' => basketRice('b')],
        ['id' => 'c', 'fit' => basketOil('c')],
    ]);

    expect(array_column($show['entries'], 'id'))->toBe(['a', 'b', 'c'])
        ->and($show['entries'][0]['amount_text'])->toMatch('/^\d+ g \(about [\d.]+ fillets?\)$/')
        ->and($show['entries'][1]['amount_text'])->toMatch('/^\d+ g$/')
        ->and($show['entries'][2]['amount_text'])->toMatch('/^\d+ g$/')
        ->and((int) $show['entries'][1]['amount_text'])->toBeGreaterThan(100);
});

it('FOOD-030 MEAL-001 MEAL-003 draws the meal\'s four bars after Add, with Targets met when the picks reach them', function () {
    $show = basketShow([], [
        ['id' => 'a', 'fit' => basketChicken('a')],
        ['id' => 'b', 'fit' => basketRice('b')],
        ['id' => 'c', 'fit' => basketOil('c')],
    ]);

    expect(array_column($show['bars'], 'label'))->toBe(['Cal', 'P', 'F', 'C'])
        ->and($show['bars'][1]['value_text'])->toEndWith('/68')
        ->and($show['targets_met'])->toBe(['text' => 'Targets met', 'tone' => 'good'])
        ->and($show['hints'])->toBe([]);
});

it('FOOD-030 MEAL-007 says what the picks cannot reach, and which curated group to add from', function () {
    $show = basketShow([], [['id' => 'a', 'fit' => basketChicken('a')]]);

    expect($show['targets_met'])->toBeNull()
        ->and(array_column($show['hints'], 'group'))->toContain('carbs_recommended')
        ->and($show['hints'][array_search('carbs_recommended', array_column($show['hints'], 'group'))]['text'])->toStartWith('Short ');
});

it('FOOD-030 MEAL-005 refits the foods already in the meal around the picks and keeps a locked one where it is', function () {
    $locked = basketChicken('kept', grams: 200, locked: true);
    $show = basketShow([$locked], [['id' => 'b', 'fit' => basketRice('b')], ['id' => 'c', 'fit' => basketOil('c')]]);

    // 200 g of chicken is 62 g protein, the rice brings the rest.
    expect((int) explode('/', $show['bars'][1]['value_text'])[0])->toBeGreaterThanOrEqual(62)
        ->and(array_column($show['entries'], 'id'))->toBe(['b', 'c']);
});

it('FOOD-030 RCP-002 gives a recipe pick its servings', function () {
    $stew = RecipeServing::food('s', 'Stew', new MealTarget(kcal: 355, proteinG: 38.5, fatG: 3.5, carbsG: 39), 0);
    $show = basketShow([], [['id' => 's', 'fit' => $stew, 'isServing' => true]]);

    expect($show['entries'][0]['amount_text'])->toMatch('/^[\d.]+ servings?$/');
});

it('FOOD-030 FOOD-028 a pick the meal already holds has no amount: Add skips it', function () {
    $show = basketShow([basketRice('kept', 150)], [['id' => 'b', 'fit' => null], ['id' => 'c', 'fit' => basketChicken('c')]]);

    expect($show['entries'][0])->toBe(['id' => 'b', 'amount_text' => null])
        ->and($show['entries'][1]['amount_text'])->not->toBeNull();
});

it('FOOD-030 a pick the fit leaves at nothing still shows the 1 g Add plans it at', function () {
    // Protein met by locked chicken; oil has nowhere to go once fat is full.
    $show = basketShow([basketChicken('kept', 220, locked: true), basketOil('oil', 28)], [['id' => 'c', 'fit' => basketOil('c')]]);

    expect($show['entries'][0]['amount_text'])->toMatch('/^\d+ g$/')
        ->and((int) $show['entries'][0]['amount_text'])->toBeGreaterThanOrEqual(1);
});
