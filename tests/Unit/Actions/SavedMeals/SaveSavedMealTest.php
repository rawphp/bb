<?php

use App\Actions\SavedMeals\SaveSavedMeal;
use App\Models\DayTarget;
use App\Models\Food;
use App\Models\PlannedFood;
use App\Models\PlannedRecipe;
use App\Models\SavedMeal;
use App\Models\SavedMealFood;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

// Ported from rp-clone ios/Tests/RPCoreTests/Features/Meal/SavedMealTests.swift (save). The action's job is the rows
// it writes, so it runs on the database.
uses(RefreshDatabase::class);

/** The user's lunch: its meal id. */
function saveSavedMealLunch(User $user): string
{
    $day = DayTarget::factory()->for($user)->create(['meals' => [['name' => 'Lunch', 'kcal' => 700, 'protein_g' => 50, 'fat_g' => 20, 'carbs_g' => 80]]]);

    return $day->meals[0]['id'];
}

/** @return list<array<string, mixed>> */
function saveSavedMealRows(string $id): array
{
    return SavedMealFood::query()->where('saved_meal_id', $id)->orderBy('position')->get()
        ->map(fn (SavedMealFood $f) => $f->only(['food_id', 'position', 'grams', 'is_cooked', 'is_locked', 'unit']))->all();
}

it('MEAL-070 save keeps the meal\'s foods with their amounts, cooked state, locks and unit', function () {
    $user = User::factory()->create();
    $meal = saveSavedMealLunch($user);
    [$chicken, $rice] = [Food::factory()->create(['name' => 'Chicken breast']), Food::factory()->create(['name' => 'Rice'])];
    PlannedFood::factory()->create(['meal_id' => $meal, 'food_id' => $chicken->id, 'position' => 0, 'grams' => 150, 'is_cooked' => true]);
    PlannedFood::factory()->create(['meal_id' => $meal, 'food_id' => $rice->id, 'position' => 2, 'grams' => 80, 'is_locked' => true, 'unit' => 'oz']);
    // The chili recipe serving is not saved: a saved meal holds foods.
    PlannedRecipe::factory()->create(['meal_id' => $meal, 'position' => 1, 'servings' => 1.5, 'is_locked' => true]);
    $id = (string) Str::ulid();

    $saved = (new SaveSavedMeal)($user, null, $id, 'Chicken and rice', $meal, []);

    expect($saved)->id->toBe($id)->user_id->toBe($user->id)->name->toBe('Chicken and rice')
        ->and(saveSavedMealRows($id))->toBe([
            ['food_id' => $chicken->id, 'position' => 0, 'grams' => 150.0, 'is_cooked' => true, 'is_locked' => false, 'unit' => 'g'],
            ['food_id' => $rice->id, 'position' => 1, 'grams' => 80.0, 'is_cooked' => false, 'is_locked' => true, 'unit' => 'oz'],
        ]);
});

it('MEAL-070 MEAL-005 save keeps each amount exactly, a locked one under 1 g included', function () {
    $user = User::factory()->create();
    $meal = saveSavedMealLunch($user);
    PlannedFood::factory()->create(['meal_id' => $meal, 'position' => 0, 'grams' => 0.5, 'is_locked' => true]);
    PlannedFood::factory()->create(['meal_id' => $meal, 'position' => 1, 'grams' => 0]);
    $id = (string) Str::ulid();

    (new SaveSavedMeal)($user, null, $id, 'Lunch', $meal, []);

    expect(array_column(saveSavedMealRows($id), 'grams'))->toBe([0.5, 0.0]);
});

it('MEAL-070 save keeps the first 50 foods of a bigger meal', function () {
    $user = User::factory()->create();
    $meal = saveSavedMealLunch($user);
    foreach (range(0, 51) as $position) {
        PlannedFood::factory()->create(['meal_id' => $meal, 'position' => $position]);
    }
    $id = (string) Str::ulid();

    (new SaveSavedMeal)($user, null, $id, 'Feast', $meal, []);

    expect(saveSavedMealRows($id))->toHaveCount(50)
        ->and(array_column(saveSavedMealRows($id), 'position'))->toBe(range(0, 49));
});

it('MEAL-070 the name is trimmed and a blank one saves nothing', function () {
    $user = User::factory()->create();
    $meal = saveSavedMealLunch($user);
    PlannedFood::factory()->create(['meal_id' => $meal]);

    expect(fn () => (new SaveSavedMeal)($user, null, (string) Str::ulid(), '   ', $meal, []))
        ->toThrow(DomainException::class, 'Give the saved meal a name.')
        ->and(SavedMeal::query()->count())->toBe(0);

    $saved = (new SaveSavedMeal)($user, null, (string) Str::ulid(), '  Oats and whey ', $meal, []);
    expect($saved->name)->toBe('Oats and whey');
});

it('MEAL-070 an empty meal cannot be saved', function () {
    $user = User::factory()->create();
    $meal = saveSavedMealLunch($user);
    PlannedRecipe::factory()->create(['meal_id' => $meal]);

    expect(fn () => (new SaveSavedMeal)($user, null, (string) Str::ulid(), 'Dinner', $meal, []))
        ->toThrow(DomainException::class, 'That meal has no foods to save.')
        ->and(SavedMeal::query()->count())->toBe(0);
});

it('MEAL-070 never saves a meal of a deleted day', function () {
    $user = User::factory()->create();
    $meal = saveSavedMealLunch($user);
    PlannedFood::factory()->create(['meal_id' => $meal]);
    DayTarget::query()->where('user_id', $user->id)->sole()->delete();

    expect(fn () => (new SaveSavedMeal)($user, null, (string) Str::ulid(), 'Lunch', $meal, []))
        ->toThrow(ModelNotFoundException::class)
        ->and(SavedMeal::query()->count())->toBe(0);
});

it('FOOD-046 never saves another user\'s custom food or a deleted one', function () {
    $user = User::factory()->create();
    $theirs = Food::factory()->custom(User::factory()->create())->create();
    $deleted = Food::factory()->custom($user)->create();
    $deleted->delete();

    foreach ([$theirs, $deleted] as $food) {
        expect(fn () => (new SaveSavedMeal)($user, null, (string) Str::ulid(), 'Snack', null, [['food_id' => $food->id, 'grams' => 30]]))
            ->toThrow(ModelNotFoundException::class);
    }
    expect(SavedMeal::query()->count())->toBe(0);
});

it('MEAL-070 never saves another user\'s meal', function () {
    $meal = saveSavedMealLunch(User::factory()->create());
    PlannedFood::factory()->create(['meal_id' => $meal]);

    expect(fn () => (new SaveSavedMeal)(User::factory()->create(), null, (string) Str::ulid(), 'Mine', $meal, []))
        ->toThrow(ModelNotFoundException::class)
        ->and(SavedMeal::query()->count())->toBe(0);
});

it('FOOD-046 saves the foods it is given, locked, raw and in grams', function () {
    $user = User::factory()->create();
    [$eggs, $toast] = [Food::factory()->create(), Food::factory()->custom($user)->create()];
    $id = (string) Str::ulid();

    (new SaveSavedMeal)($user, null, $id, 'Scanned breakfast', null, [
        ['food_id' => $eggs->id, 'grams' => 120.4],
        ['food_id' => $toast->id, 'grams' => 60],
    ]);

    expect(saveSavedMealRows($id))->toBe([
        ['food_id' => $eggs->id, 'position' => 0, 'grams' => 120.4, 'is_cooked' => false, 'is_locked' => true, 'unit' => 'g'],
        ['food_id' => $toast->id, 'position' => 1, 'grams' => 60.0, 'is_cooked' => false, 'is_locked' => true, 'unit' => 'g'],
    ]);
});

it('FOOD-046 refuses more than 50 foods', function () {
    $user = User::factory()->create();
    $food = Food::factory()->create();
    $foods = array_fill(0, 51, ['food_id' => $food->id, 'grams' => 10]);

    expect(fn () => (new SaveSavedMeal)($user, null, (string) Str::ulid(), 'Too much', null, $foods))
        ->toThrow(DomainException::class, 'A saved meal holds at most 50 foods.')
        ->and(SavedMeal::query()->count())->toBe(0);
});

it('CAP-019 a save under an id the user already has returns that saved meal unchanged, never replacing its foods', function () {
    $user = User::factory()->create();
    [$eggs, $oats] = [Food::factory()->create(), Food::factory()->create()];
    $id = (string) Str::ulid();
    $first = (new SaveSavedMeal)($user, null, $id, 'Breakfast', null, [['food_id' => $eggs->id, 'grams' => 100]]);

    $again = (new SaveSavedMeal)($user, $first, $id, 'Big breakfast', null, [['food_id' => $oats->id, 'grams' => 80]]);

    expect($again->name)->toBe('Breakfast')
        ->and(SavedMeal::query()->count())->toBe(1)
        ->and(SavedMeal::query()->findOrFail($id)->name)->toBe('Breakfast')
        ->and(saveSavedMealRows($id))->toBe([['food_id' => $eggs->id, 'position' => 0, 'grams' => 100.0, 'is_cooked' => false, 'is_locked' => true, 'unit' => 'g']]);
});
