<?php

use App\Actions\Meals\RebalanceMeal;
use App\Domain\MealFit\Food as FitFood;
use App\Domain\MealFit\MealFit;
use App\Domain\MealFit\MealFood;
use App\Domain\Model\MealTarget;
use App\Models\DayTargetMeal;
use App\Models\Food;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MealBuilderSeed as Seed;

// Ported from rp-clone MealModelTests (MEAL-006) and MealRecipeTests (RCP-002 rebalance), on the database.
uses(RefreshDatabase::class);

it('MEAL-006 fits every unlocked food to the meal\'s targets and keeps a locked one', function () {
    $user = User::factory()->create();
    $meal = Seed::dinner($user);
    $chicken = Seed::chicken();
    $rice = Seed::rice();
    $oil = Seed::oil();
    $rows = [Seed::planFood($meal, $chicken, 150, 0), Seed::planFood($meal, $rice, 80, 1), Seed::planFood($meal, $oil, 20, 2, locked: true)];

    $changed = app(RebalanceMeal::class)($user, $meal);

    $fit = fn (Food $f, string $id, float $grams, bool $locked) => new MealFood($id, new FitFood(id: $f->id, name: $f->name, kcal: $f->kcal, proteinG: $f->protein, fatG: $f->fat, carbsG: $f->carbs, cookedRatio: $f->cooked_ratio), $grams, isLocked: $locked);
    $expected = (new MealFit)->fit([$fit($chicken, 'a', 150, false), $fit($rice, 'b', 80, false), $fit($oil, 'c', 20, true)], new MealTarget(kcal: 1040, proteinG: 68, fatG: 28, carbsG: 128))->foods;
    expect($changed)->toBeInstanceOf(DayTargetMeal::class)
        ->and($rows[0]->fresh()->grams)->toBe($expected[0]->grams)
        ->and($rows[1]->fresh()->grams)->toBe($expected[1]->grams)
        ->and($rows[2]->fresh()->grams)->toBe(20.0)
        ->and($rows[0]->fresh()->grams)->not->toBe(150.0);
});

it('RCP-002 rebalances an unlocked serving like a food and keeps a locked one', function () {
    $user = User::factory()->create();
    $meal = Seed::dinner($user);
    $rice = Seed::planFood($meal, Seed::rice(), 20, 0);
    $open = Seed::planRecipe($meal, Seed::stew(), 0.2, 1);
    $locked = Seed::planRecipe($meal, Seed::stew(), 0.7, 2, locked: true);

    app(RebalanceMeal::class)($user, $meal);

    expect($open->fresh()->servings)->not->toBe(0.2)
        ->and($locked->fresh()->servings)->toBe(0.7)
        ->and($locked->fresh()->is_locked)->toBeTrue()
        ->and($rice->fresh()->grams)->not->toBe(20.0);
});

it('MEAL-006 rebalances an empty meal without writing, and saves an amount fitted to nothing as 1 g', function () {
    $user = User::factory()->create();
    $empty = Seed::dinner($user);

    expect(app(RebalanceMeal::class)($user, $empty)->id)->toBe($empty);

    $full = Seed::dinner(User::factory()->create(), ['kcal' => 400, 'protein_g' => 10, 'fat_g' => 1, 'carbs_g' => 96]);
    $rice = Seed::planFood($full, Seed::rice(), 400, 0, locked: true);
    $oil = Seed::planFood($full, Seed::oil(), 30, 1);
    app(RebalanceMeal::class)($oil->user, $full);

    expect($oil->fresh()->grams)->toBe(1.0)
        ->and($rice->fresh()->grams)->toBe(400.0);
});

it('MEAL-006 answers not found for another user\'s meal', function () {
    $theirs = Seed::dinner(User::factory()->create());

    expect(fn () => app(RebalanceMeal::class)(User::factory()->create(), $theirs))->toThrow(ModelNotFoundException::class);
});
