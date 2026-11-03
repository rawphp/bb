<?php

use App\Actions\Meals\SetMealFoodAmount;
use App\Capabilities\Support\FieldRefused;
use App\Domain\Pantry\FoodUnit;
use App\Models\DayTargetMeal;
use App\Models\PlannedFood;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MealBuilderSeed as Seed;

// Ported from rp-clone MealModelTests (MEAL-004, MEAL-005) and MealRecipeTests (RCP-002), on the database.
uses(RefreshDatabase::class);

/** @return array{0: User, 1: string, 2: array<string, PlannedFood>} the user, their dinner and its chicken, rice and oil (oil locked) */
function setAmountDinner(): array
{
    $user = User::factory()->create();
    $meal = Seed::dinner($user);

    return [$user, $meal, [
        'chicken' => Seed::planFood($meal, Seed::chicken(), 150, 0),
        'rice' => Seed::planFood($meal, Seed::rice(), 80, 1),
        'oil' => Seed::planFood($meal, Seed::oil(), 20, 2, locked: true),
    ]];
}

it('MEAL-004 sets typed grams on the food, rebalances the other unlocked foods and keeps a locked one exactly', function () {
    [$user, $meal, $rows] = setAmountDinner();

    $changed = app(SetMealFoodAmount::class)($user, $meal, $rows['chicken']->id, 250, 'g');

    expect($changed)->toBeInstanceOf(DayTargetMeal::class)
        ->and($rows['chicken']->fresh()->grams)->toBe(250.0)
        ->and($rows['rice']->fresh()->grams)->not->toBe(80.0)
        ->and($rows['oil']->fresh()->grams)->toBe(20.0)
        ->and($rows['oil']->fresh()->is_locked)->toBeTrue();
});

it('MEAL-004 turns ounces and the first household unit into grams', function () {
    [$user, $meal, $rows] = setAmountDinner();

    app(SetMealFoodAmount::class)($user, $meal, $rows['chicken']->id, 4, 'oz');
    expect($rows['chicken']->fresh()->grams)->toBe(round(4 * FoodUnit::GRAMS_PER_OUNCE));

    // Two cups of 185 g.
    app(SetMealFoodAmount::class)($user, $meal, $rows['rice']->id, 2, 'household');
    expect($rows['rice']->fresh()->grams)->toBe(370.0);
});

it('MEAL-004 counts a cooked food\'s household unit as raw grams and saves the cooked grams', function () {
    $user = User::factory()->create();
    $meal = Seed::dinner($user);
    $chicken = Seed::planFood($meal, Seed::chicken(), 150, 0, cooked: true);

    // One fillet is 150 g raw, 112.5 g cooked.
    app(SetMealFoodAmount::class)($user, $meal, $chicken->id, 1, 'household');

    expect($chicken->fresh()->grams)->toBe(113.0);
});

it('MEAL-004 leaves the unit the food is shown in alone', function () {
    $user = User::factory()->create();
    $meal = Seed::dinner($user);
    $rice = Seed::planFood($meal, Seed::rice(), 80, 0, unit: 'oz');

    app(SetMealFoodAmount::class)($user, $meal, $rice->id, 100, 'g');

    expect($rice->fresh()->unit)->toBe('oz')
        ->and($rice->fresh()->grams)->toBe(100.0);
});

it('MEAL-004 holds a food typed as zero at zero and saves it as 1 g', function () {
    [$user, $meal, $rows] = setAmountDinner();

    app(SetMealFoodAmount::class)($user, $meal, $rows['rice']->id, 0, 'g');

    expect($rows['rice']->fresh()->grams)->toBe(1.0);
});

it('RCP-002 sets typed servings on a recipe serving, at least a tenth, and the foods rebalance', function () {
    [$user, $meal, $rows] = setAmountDinner();
    $serving = Seed::planRecipe($meal, Seed::stew(), 1, 3);

    app(SetMealFoodAmount::class)($user, $meal, $serving->id, 2.5, 'servings');
    expect($serving->fresh()->servings)->toBe(2.5)
        ->and($rows['rice']->fresh()->grams)->not->toBe(80.0);

    app(SetMealFoodAmount::class)($user, $meal, $serving->id, 0, 'servings');
    expect($serving->fresh()->servings)->toBe(0.1);
});

it('MEAL-004 RCP-002 refuses a unit the entry does not have, naming the unit, and writes nothing', function (string $entry, float $value, string $unit, string $message) {
    [$user, $meal, $rows] = setAmountDinner();
    $rows['serving'] = Seed::planRecipe($meal, Seed::stew(), 1, 3);
    $before = $rows['rice']->fresh()->grams;

    try {
        app(SetMealFoodAmount::class)($user, $meal, $rows[$entry]->id, $value, $unit);
        $this->fail('Expected a refusal.');
    } catch (FieldRefused $refused) {
        expect($refused->field)->toBe('unit')
            ->and($refused->getMessage())->toBe($message);
    }

    expect($rows['rice']->fresh()->grams)->toBe($before);
})->with([
    'servings on a food' => ['rice', 2, 'servings', 'Servings are for a recipe; set a food in g, oz or a household unit.'],
    'grams on a recipe' => ['serving', 200, 'g', 'A recipe is set in servings.'],
    'ounces on a recipe' => ['serving', 2, 'oz', 'A recipe is set in servings.'],
    'a household unit on a food with none' => ['oil', 2, 'household', 'This food has no household unit.'],
]);

it('MEAL-004 answers not found for another meal\'s entry, another user\'s meal and an unknown entry', function () {
    [$user, $meal, $rows] = setAmountDinner();
    $other = Seed::planFood(Seed::breakfast($user), Seed::rice(), 50, 0);
    $theirs = Seed::dinner(User::factory()->create());

    expect(fn () => app(SetMealFoodAmount::class)($user, $meal, $other->id, 60, 'g'))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(SetMealFoodAmount::class)($user, $theirs, $rows['rice']->id, 60, 'g'))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(SetMealFoodAmount::class)($user, $meal, '01jbx8z6q7m0v2k3n4p5r6s7t8', 60, 'g'))->toThrow(ModelNotFoundException::class)
        ->and($other->fresh()->grams)->toBe(50.0)
        ->and($rows['rice']->fresh()->grams)->toBe(80.0);
});
