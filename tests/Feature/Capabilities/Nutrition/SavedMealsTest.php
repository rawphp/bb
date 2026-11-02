<?php

use App\Capabilities\Support\HostDefinition;
use App\Models\DayTarget;
use App\Models\Food;
use App\Models\PlannedFood;
use App\Models\SavedMeal;
use App\Models\SavedMealFood;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Tests\Support\ScreenFixture;

// The HTTP seam of saved_meals_show, saved_meal_save, saved_meal_rename, saved_meal_delete and meal_saved_meal_load.
// The rules are unit tests (SavedMealsTest, SavedMealLoadTest and the SavedMeals action tests).

afterEach(fn () => Carbon::setTestNow());

function savedMealCall(?User $user, string $name, array $input = []): TestResponse
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson("/capabilities/{$name}", $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

/** A saved meal of the user's with this many foods. */
function savedMealOf(User $user, string $name, int $foods): SavedMeal
{
    $meal = SavedMeal::factory()->for($user)->create(['name' => $name]);
    foreach (range(0, $foods - 1) as $position) {
        SavedMealFood::factory()->create(['saved_meal_id' => $meal->id, 'position' => $position]);
    }

    return $meal;
}

/** The user's one meal on 2026-10-13: its id. */
function savedMealDayMeal(User $user): string
{
    return DayTarget::factory()->for($user)->create(['date' => '2026-10-13'])->meals[0]['id'];
}

it('MEAL-070 MEAL-071 AGT-010 saved_meal_delete is destructive and no saved meal write is reversible', function () {
    $registry = app(CapabilityRegistry::class);

    expect(HostDefinition::isDestructive($registry->get('saved_meal_delete')))->toBeTrue();
    foreach (['saved_meal_save', 'saved_meal_rename', 'meal_saved_meal_load'] as $name) {
        expect(HostDefinition::isDestructive($registry->get($name)))->toBeFalse();
    }
    foreach (['saved_meal_save', 'saved_meal_rename', 'saved_meal_delete', 'meal_saved_meal_load'] as $name) {
        expect(HostDefinition::isReversible($registry->get($name)))->toBeFalse();
    }
    expect(HostDefinition::affects($registry->get('saved_meal_save')))->toBe(['saved_meals_show'])
        ->and(HostDefinition::affects($registry->get('saved_meal_rename')))->toBe(['saved_meals_show'])
        ->and(HostDefinition::affects($registry->get('saved_meal_delete')))->toBe(['saved_meals_show'])
        ->and(HostDefinition::affects($registry->get('meal_saved_meal_load')))->toBe(['today_show']);
});

it('MEAL-070 MEAL-071 refuses every saved meal capability when signed out', function (string $name, array $input) {
    savedMealCall(null, $name, $input)->assertUnauthorized();
})->with([
    'saved_meals_show' => ['saved_meals_show', []],
    'saved_meal_save' => ['saved_meal_save', ['id' => '01JBX0A1B2C3D4E5F6G7H8J9K0', 'name' => 'Dinner', 'foods' => []]],
    'saved_meal_rename' => ['saved_meal_rename', ['saved_meal_id' => '01JBX0A1B2C3D4E5F6G7H8J9K0', 'name' => 'Dinner']],
    'saved_meal_delete' => ['saved_meal_delete', ['saved_meal_id' => '01JBX0A1B2C3D4E5F6G7H8J9K0']],
    'meal_saved_meal_load' => ['meal_saved_meal_load', ['meal_id' => '0190a000-0000-7000-8000-000000000000', 'saved_meal_id' => '01JBX0A1B2C3D4E5F6G7H8J9K0']],
]);

it('MEAL-071 lists the user\'s saved meals by name with their food counts', function () {
    Carbon::setTestNow('2026-11-03 09:00:00');
    $user = User::factory()->create();
    $zed = savedMealOf($user, 'Zed', 1);
    $bulk = savedMealOf($user, 'Bulk dinner', 3);
    savedMealOf(User::factory()->create(), 'Another user\'s meal', 2);

    $response = savedMealCall($user, 'saved_meals_show')->assertOk();

    expect($response->json('data'))->toBe([
        'meals' => [
            ['saved_meal_id' => $bulk->id, 'name' => 'Bulk dinner', 'detail' => '3 foods'],
            ['saved_meal_id' => $zed->id, 'name' => 'Zed', 'detail' => '1 food'],
        ],
        'empty_text' => null,
    ]);
    assertMatchesScreenFixture('saved_meals_show', $response->json('data'));
});

it('MEAL-071 says how to save a meal when the user has none', function () {
    Carbon::setTestNow('2026-11-03 09:00:00');

    $response = savedMealCall(User::factory()->create(), 'saved_meals_show')
        ->assertOk()
        ->assertJsonPath('data.meals', [])
        ->assertJsonPath('data.empty_text', 'No saved meals yet. Save one from a meal\'s menu.');

    assertMatchesScreenFixture('saved_meals_show', $response->json('data'));
    expect(ScreenFixture::differences(ScreenFixture::load('saved_meals_show.empty'), $response->json('data')))->toBe([]);
});

it('MEAL-070 saves a meal\'s foods under the client\'s id and says the list changed', function () {
    $user = User::factory()->create();
    $meal = savedMealDayMeal($user);
    PlannedFood::factory()->create(['meal_id' => $meal, 'grams' => 120, 'is_locked' => true]);
    $id = (string) Str::ulid();

    savedMealCall($user, 'saved_meal_save', ['id' => $id, 'name' => ' Usual lunch ', 'meal_id' => $meal])
        ->assertOk()
        ->assertJsonPath('data.invalidates', ['saved_meals_show{}'])
        ->assertJsonPath('data.toast', 'Saved Usual lunch');

    expect(SavedMeal::query()->findOrFail($id))->user_id->toBe($user->id)->name->toBe('Usual lunch')
        ->and(savedMealCall($user, 'saved_meals_show')->json('data.meals.0'))->toBe(['saved_meal_id' => $id, 'name' => 'Usual lunch', 'detail' => '1 food']);
});

it('CAP-019 answers a save under the user\'s own saved meal\'s id with that saved meal, unchanged', function () {
    $user = User::factory()->create();
    $meal = savedMealOf($user, 'Bulk dinner', 2);

    savedMealCall($user, 'saved_meal_save', ['id' => $meal->id, 'name' => 'Other', 'foods' => [['food_id' => Food::factory()->create()->id, 'grams' => 50]]])
        ->assertOk()
        ->assertJsonPath('data.toast', 'Saved Bulk dinner');

    expect($meal->fresh()->name)->toBe('Bulk dinner')->and($meal->foods()->count())->toBe(2);
});

it('FOOD-046 saves a scanned meal\'s foods', function () {
    $user = User::factory()->create();
    $id = (string) Str::ulid();

    savedMealCall($user, 'saved_meal_save', ['id' => $id, 'name' => 'Scanned', 'foods' => [['food_id' => Food::factory()->create()->id, 'grams' => 90]]])
        ->assertOk();

    expect(SavedMealFood::query()->where('saved_meal_id', $id)->sole())->grams->toBe(90.0)->is_locked->toBeTrue();
});

it('MEAL-070 answers 404 for saving another user\'s meal, a food the user cannot see, or over another user\'s saved meal', function () {
    $user = User::factory()->create();
    $theirMeal = savedMealDayMeal(User::factory()->create());
    PlannedFood::factory()->create(['meal_id' => $theirMeal]);
    $theirFood = Food::factory()->custom(User::factory()->create())->create();
    $theirSaved = savedMealOf(User::factory()->create(), 'Theirs', 1);

    savedMealCall($user, 'saved_meal_save', ['id' => (string) Str::ulid(), 'name' => 'Mine', 'meal_id' => $theirMeal])->assertNotFound();
    savedMealCall($user, 'saved_meal_save', ['id' => (string) Str::ulid(), 'name' => 'Mine', 'foods' => [['food_id' => $theirFood->id, 'grams' => 50]]])->assertNotFound();
    savedMealCall($user, 'saved_meal_save', ['id' => $theirSaved->id, 'name' => 'Mine', 'foods' => [['food_id' => Food::factory()->create()->id, 'grams' => 50]]])->assertNotFound();

    expect(SavedMeal::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and($theirSaved->fresh()->name)->toBe('Theirs');
});

it('MEAL-070 answers 422 saying why for a blank name or an empty meal', function () {
    $user = User::factory()->create();
    $meal = savedMealDayMeal($user);

    savedMealCall($user, 'saved_meal_save', ['id' => (string) Str::ulid(), 'name' => 'Dinner', 'meal_id' => $meal])
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'That meal has no foods to save.');
    PlannedFood::factory()->create(['meal_id' => $meal]);
    savedMealCall($user, 'saved_meal_save', ['id' => (string) Str::ulid(), 'name' => '   ', 'meal_id' => $meal])
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'Give the saved meal a name.');

    expect(SavedMeal::query()->count())->toBe(0);
});

it('MEAL-071 renames the user\'s saved meal and says the list changed', function () {
    $user = User::factory()->create();
    $meal = savedMealOf($user, 'Bulk dinner', 3);

    savedMealCall($user, 'saved_meal_rename', ['saved_meal_id' => $meal->id, 'name' => '  Cut dinner '])
        ->assertOk()
        ->assertJsonPath('data.invalidates', ['saved_meals_show{}']);

    expect($meal->fresh()->name)->toBe('Cut dinner');
});

it('MEAL-071 answers 404 for renaming or deleting another user\'s saved meal', function () {
    $theirs = savedMealOf(User::factory()->create(), 'Theirs', 1);
    $user = User::factory()->create();

    savedMealCall($user, 'saved_meal_rename', ['saved_meal_id' => $theirs->id, 'name' => 'Mine now'])->assertNotFound();
    savedMealCall($user, 'saved_meal_delete', ['saved_meal_id' => $theirs->id])->assertNotFound();

    expect($theirs->fresh())->name->toBe('Theirs');
});

it('MEAL-071 answers 422 for a blank or missing name', function () {
    $user = User::factory()->create();
    $meal = savedMealOf($user, 'Bulk dinner', 1);

    savedMealCall($user, 'saved_meal_rename', ['saved_meal_id' => $meal->id, 'name' => '  '])
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'Give the saved meal a name.');
    savedMealCall($user, 'saved_meal_rename', ['saved_meal_id' => $meal->id])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'name');

    expect($meal->fresh()->name)->toBe('Bulk dinner');
});

it('MEAL-071 deletes the user\'s saved meal and says the list changed', function () {
    $user = User::factory()->create();
    $meal = savedMealOf($user, 'Bulk dinner', 2);

    savedMealCall($user, 'saved_meal_delete', ['saved_meal_id' => $meal->id])
        ->assertOk()
        ->assertJsonPath('data.invalidates', ['saved_meals_show{}']);

    expect(savedMealCall($user, 'saved_meals_show')->json('data.meals'))->toBe([]);
});

it('MEAL-071 answers 422 naming saved_meal_id when it is missing', function () {
    savedMealCall(User::factory()->create(), 'saved_meal_delete', [])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'saved_meal_id');
});

it('MEAL-070 loads a saved meal into a meal and says which day changed', function () {
    $user = User::factory()->create();
    $meal = savedMealDayMeal($user);
    $saved = savedMealOf($user, 'Bulk dinner', 2);

    savedMealCall($user, 'meal_saved_meal_load', ['meal_id' => $meal, 'saved_meal_id' => $saved->id])
        ->assertOk()
        ->assertJsonPath('data.invalidates', ['today_show{}', 'today_show{"date":"2026-10-13"}'])
        ->assertJsonPath('data.toast', 'Loaded Bulk dinner');

    expect(PlannedFood::query()->where('meal_id', $meal)->count())->toBe(2);
});

it('MEAL-070 says when a saved meal adds nothing to the meal', function () {
    $user = User::factory()->create();
    $meal = savedMealDayMeal($user);
    $saved = savedMealOf($user, 'Bulk dinner', 1);
    PlannedFood::factory()->create(['meal_id' => $meal, 'food_id' => $saved->foods()->sole()->food_id]);

    savedMealCall($user, 'meal_saved_meal_load', ['meal_id' => $meal, 'saved_meal_id' => $saved->id])
        ->assertOk()
        ->assertJsonPath('data.invalidates', [])
        ->assertJsonPath('data.toast', 'Every food in Bulk dinner is already in this meal');
});

it('MEAL-070 answers 404 for loading into another user\'s meal or from another user\'s saved meal', function () {
    $user = User::factory()->create();
    $mine = savedMealDayMeal($user);
    $theirMeal = savedMealDayMeal(User::factory()->create());
    $saved = savedMealOf($user, 'Mine', 1);
    $theirSaved = savedMealOf(User::factory()->create(), 'Theirs', 1);

    savedMealCall($user, 'meal_saved_meal_load', ['meal_id' => $theirMeal, 'saved_meal_id' => $saved->id])->assertNotFound();
    savedMealCall($user, 'meal_saved_meal_load', ['meal_id' => $mine, 'saved_meal_id' => $theirSaved->id])->assertNotFound();

    expect(PlannedFood::query()->count())->toBe(0);
});

it('MEAL-070 answers 422 naming saved_meal_id when it is missing', function () {
    $user = User::factory()->create();

    savedMealCall($user, 'meal_saved_meal_load', ['meal_id' => savedMealDayMeal($user)])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'saved_meal_id');
});
