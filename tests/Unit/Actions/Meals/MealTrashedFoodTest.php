<?php

use App\Actions\Meals\RebalanceMeal;
use App\Actions\Meals\SetMealFoodLock;
use App\Actions\Meals\StepMealFood;
use App\Domain\MealFit\Food as FitFood;
use App\Domain\MealFit\MealFit;
use App\Domain\MealFit\MealFood;
use App\Domain\Model\MealTarget;
use App\Models\Food;
use App\Models\PlannedFood;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MealBuilderSeed as Seed;

// FOOD-061: a deleted custom food is deleted softly, so the meals that plan it keep reading it. The meal screen counts
// it at its macros (MealPayload), so the edits fit it like any other food, and it stays in the meal.
uses(RefreshDatabase::class);

function trashedFit(Food $food, string $id, float $grams, bool $locked = false): MealFood
{
    return new MealFood($id, new FitFood(id: $food->id, name: $food->name, kcal: $food->kcal, proteinG: $food->protein, fatG: $food->fat, carbsG: $food->carbs, cookedRatio: $food->cooked_ratio), $grams, isLocked: $locked);
}

/** @return array{0: string, 1: list<PlannedFood>, 2: list<Food>} a dinner of chicken, rice and oil, the chicken a deleted custom food */
function trashedDinner(User $user): array
{
    $meal = Seed::dinner($user);
    $foods = [Seed::chicken(), Seed::rice(), Seed::oil()];
    $foods[0]->forceFill(['user_id' => $user->id])->save();
    $rows = [Seed::planFood($meal, $foods[0], 150, 0), Seed::planFood($meal, $foods[1], 80, 1), Seed::planFood($meal, $foods[2], 20, 2)];
    $foods[0]->delete();
    expect($foods[0]->fresh()->trashed())->toBeTrue();

    return [$meal, $rows, $foods];
}

it('MEAL-006 FOOD-061 rebalances a meal holding a deleted custom food, fitting it with the others', function () {
    $user = User::factory()->create();
    [$meal, $rows, $foods] = trashedDinner($user);

    app(RebalanceMeal::class)($user, $meal);

    $expected = (new MealFit)->fit([trashedFit($foods[0], 'a', 150), trashedFit($foods[1], 'b', 80), trashedFit($foods[2], 'c', 20)], new MealTarget(kcal: 1040, proteinG: 68, fatG: 28, carbsG: 128))->foods;
    expect(array_map(fn ($row) => $row->fresh()?->grams, $rows))->toBe(array_map(fn (MealFood $f) => max(1.0, $f->grams), $expected))
        ->and($rows[0]->fresh()->grams)->not->toBe(150.0);
});

it('MEAL-004 FOOD-061 steps a food in a meal holding a deleted custom food, and the deleted one rebalances', function () {
    $user = User::factory()->create();
    [$meal, $rows, $foods] = trashedDinner($user);

    app(StepMealFood::class)($user, $meal, $rows[1]->id, 'up');

    $expected = (new MealFit)->setAmount(85, 'b', [trashedFit($foods[0], 'a', 150), trashedFit($foods[1], 'b', 80), trashedFit($foods[2], 'c', 20)], new MealTarget(kcal: 1040, proteinG: 68, fatG: 28, carbsG: 128))->foods;
    expect($rows[1]->fresh()->grams)->toBe(85.0)
        ->and(array_map(fn ($row) => $row->fresh()->grams, $rows))->toBe(array_map(fn (MealFood $f) => max(1.0, $f->grams), $expected));
});

it('MEAL-005 FOOD-061 locks a deleted custom food, which then keeps its grams through a rebalance', function () {
    $user = User::factory()->create();
    [$meal, $rows] = trashedDinner($user);

    app(SetMealFoodLock::class)($user, $meal, $rows[0]->id, true);
    app(RebalanceMeal::class)($user, $meal);

    expect($rows[0]->fresh()->is_locked)->toBeTrue()
        ->and($rows[0]->fresh()->grams)->toBe(150.0)
        ->and($rows[1]->fresh()->grams)->not->toBe(80.0);
});
