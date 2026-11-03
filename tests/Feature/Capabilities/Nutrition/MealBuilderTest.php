<?php

use App\Capabilities\Nutrition\MealReads;
use App\Capabilities\Support\HostDefinition;
use App\Models\DayTargetMeal;
use App\Models\Food;
use App\Models\PlannedFood;
use App\Models\PlannedRecipe;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Tests\Support\MealBuilderSeed as Seed;

// The HTTP seam of meal_food_add, meal_food_remove, meal_foods_clear, meal_food_amount_set, meal_food_step,
// meal_food_lock_set and meal_rebalance. The rules are unit tests (MealEditTest and the Meals action tests).

beforeEach(fn () => Carbon::setTestNow('2026-10-13 09:00:00'));

afterEach(fn () => Carbon::setTestNow());

const MEAL_BUILDER_WRITES = ['meal_food_add', 'meal_food_remove', 'meal_foods_clear', 'meal_food_amount_set', 'meal_food_step', 'meal_food_lock_set', 'meal_rebalance'];

function builderCall(?User $user, string $name, array $input = []): TestResponse
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson("/capabilities/{$name}", $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

/** @return list<string> the read keys a change to this meal invalidates */
function builderKeys(string $meal): array
{
    return MealReads::keys(DayTargetMeal::query()->findOrFail($meal));
}

it('MEAL-002 MEAL-004 MEAL-005 MEAL-006 MEAL-008 CAP-011 AGT-010 lists the meal reads in affects, makes only the deletes destructive and none reversible', function () {
    $registry = app(CapabilityRegistry::class);

    foreach (MEAL_BUILDER_WRITES as $name) {
        expect(HostDefinition::affects($registry->get($name)))->toBe(MealReads::AFFECTS, $name)
            ->and(HostDefinition::isReversible($registry->get($name)))->toBeFalse($name)
            ->and(HostDefinition::isDestructive($registry->get($name)))->toBe(in_array($name, ['meal_food_remove', 'meal_foods_clear'], true), $name);
    }
    expect(MealReads::AFFECTS)->toBe(['meal_show', 'nutrition_show', 'today_show', 'week_show']);
});

it('MEAL-002 MEAL-004 MEAL-005 MEAL-006 MEAL-008 refuses every meal builder write when signed out', function (string $name, array $input) {
    builderCall(null, $name, $input)->assertUnauthorized();
})->with([
    'meal_food_add' => ['meal_food_add', ['meal_id' => '0190a000-0000-7000-8000-000000000000', 'entries' => [['id' => '01JBX0A1B2C3D4E5F6G7H8J9K0', 'food_id' => '01JBX0A1B2C3D4E5F6G7H8J9K1']]]],
    'meal_food_remove' => ['meal_food_remove', ['meal_id' => '0190a000-0000-7000-8000-000000000000', 'entry_id' => '01JBX0A1B2C3D4E5F6G7H8J9K0']],
    'meal_foods_clear' => ['meal_foods_clear', ['meal_id' => '0190a000-0000-7000-8000-000000000000']],
    'meal_food_amount_set' => ['meal_food_amount_set', ['meal_id' => '0190a000-0000-7000-8000-000000000000', 'entry_id' => '01JBX0A1B2C3D4E5F6G7H8J9K0', 'value' => 100, 'unit' => 'g']],
    'meal_food_step' => ['meal_food_step', ['meal_id' => '0190a000-0000-7000-8000-000000000000', 'entry_id' => '01JBX0A1B2C3D4E5F6G7H8J9K0', 'direction' => 'up']],
    'meal_food_lock_set' => ['meal_food_lock_set', ['meal_id' => '0190a000-0000-7000-8000-000000000000', 'entry_id' => '01JBX0A1B2C3D4E5F6G7H8J9K0', 'locked' => true]],
    'meal_rebalance' => ['meal_rebalance', ['meal_id' => '0190a000-0000-7000-8000-000000000000']],
]);

it('MEAL-002 CAP-019 adds foods under the client\'s ids and answers with the meal\'s read keys', function () {
    $user = User::factory()->create();
    $meal = Seed::dinner($user);
    $food = Seed::rice();
    $recipe = Seed::stew();
    [$foodEntry, $recipeEntry] = [strtolower((string) Str::ulid()), strtolower((string) Str::ulid())];
    $input = ['meal_id' => $meal, 'entries' => [['id' => $foodEntry, 'food_id' => $food->id], ['id' => $recipeEntry, 'recipe_id' => $recipe->id]]];

    builderCall($user, 'meal_food_add', $input)->assertOk()->assertJsonPath('data.invalidates', builderKeys($meal));
    // A retry under new idempotency, with the same entry ids, adds nothing.
    builderCall($user, 'meal_food_add', $input)->assertOk();

    expect(PlannedFood::query()->where('meal_id', $meal)->pluck('id')->all())->toBe([$foodEntry])
        ->and(PlannedRecipe::query()->where('meal_id', $meal)->pluck('id')->all())->toBe([$recipeEntry]);
});

it('MEAL-002 answers 404 for another user\'s meal or a food or recipe the user cannot see, and 422 for a bad entry', function () {
    $user = User::factory()->create();
    $mine = Seed::dinner($user);
    $theirs = Seed::dinner(User::factory()->create());
    $custom = Food::factory()->create(['user_id' => User::factory()->create()->id]);
    $entry = fn (array $extra) => ['id' => (string) Str::ulid()] + $extra;

    builderCall($user, 'meal_food_add', ['meal_id' => $theirs, 'entries' => [$entry(['food_id' => Seed::rice()->id])]])->assertNotFound();
    builderCall($user, 'meal_food_add', ['meal_id' => $mine, 'entries' => [$entry(['food_id' => $custom->id])]])->assertNotFound();
    builderCall($user, 'meal_food_add', ['meal_id' => $mine, 'entries' => [$entry(['recipe_id' => Seed::stew(User::factory()->create())->id])]])->assertNotFound();
    builderCall($user, 'meal_food_add', ['meal_id' => $mine, 'entries' => [['id' => 'not-a-ulid', 'food_id' => Seed::rice()->id]]])
        ->assertStatus(422)->assertJsonPath('error.violations.0.field', 'entries.0.id');
    builderCall($user, 'meal_food_add', ['meal_id' => $mine, 'entries' => [$entry(['food_id' => Seed::rice()->id, 'recipe_id' => Seed::stew()->id])]])->assertStatus(422);
    builderCall($user, 'meal_food_add', ['meal_id' => $mine, 'entries' => [$entry(['recipe_id' => Seed::stew()->id, 'grams' => 50])]])
        ->assertStatus(422)->assertJsonPath('error.violations.0.field', 'entries.0.grams');

    expect(PlannedFood::query()->count() + PlannedRecipe::query()->count())->toBe(0);
});

it('MEAL-008 removes one entry and answers with the meal\'s read keys', function () {
    $user = User::factory()->create();
    $meal = Seed::dinner($user);
    $rice = Seed::planFood($meal, Seed::rice(), 80, 0);
    $other = Seed::planFood(Seed::breakfast($user), Seed::rice(), 50, 0);

    builderCall($user, 'meal_food_remove', ['meal_id' => Seed::dinner(User::factory()->create()), 'entry_id' => $rice->id])->assertNotFound();
    builderCall($user, 'meal_food_remove', ['meal_id' => $meal, 'entry_id' => $other->id])->assertNotFound();
    builderCall($user, 'meal_food_remove', ['meal_id' => $meal])->assertStatus(422)->assertJsonPath('error.violations.0.field', 'entry_id');
    expect($rice->fresh())->not->toBeNull();

    builderCall($user, 'meal_food_remove', ['meal_id' => $meal, 'entry_id' => $rice->id])->assertOk()->assertJsonPath('data.invalidates', builderKeys($meal));

    expect(PlannedFood::query()->whereKey($rice->id)->exists())->toBeFalse()
        ->and($other->fresh())->not->toBeNull();
});

it('MEAL-009 clears the meal\'s foods and answers with the meal\'s read keys', function () {
    $user = User::factory()->create();
    $meal = Seed::dinner($user);
    Seed::planFood($meal, Seed::rice(), 80, 0);
    $serving = Seed::planRecipe($meal, Seed::stew(), 1, 1);

    builderCall($user, 'meal_foods_clear', ['meal_id' => Seed::dinner(User::factory()->create())])->assertNotFound();
    builderCall($user, 'meal_foods_clear', [])->assertStatus(422)->assertJsonPath('error.violations.0.field', 'meal_id');
    builderCall($user, 'meal_foods_clear', ['meal_id' => $meal])->assertOk()->assertJsonPath('data.invalidates', builderKeys($meal));

    expect(PlannedFood::query()->where('meal_id', $meal)->count())->toBe(0)
        ->and($serving->fresh())->not->toBeNull();
});

it('MEAL-004 RCP-002 sets a typed amount and answers with the meal\'s read keys', function () {
    $user = User::factory()->create();
    $meal = Seed::dinner($user);
    $rice = Seed::planFood($meal, Seed::rice(), 80, 0);
    $serving = Seed::planRecipe($meal, Seed::stew(), 1, 1);
    $other = Seed::planFood(Seed::breakfast($user), Seed::rice(), 50, 0);
    $input = fn (string $entry, float $value, string $unit) => ['meal_id' => $meal, 'entry_id' => $entry, 'value' => $value, 'unit' => $unit];

    builderCall($user, 'meal_food_amount_set', ['meal_id' => Seed::dinner(User::factory()->create())] + $input($rice->id, 100, 'g'))->assertNotFound();
    builderCall($user, 'meal_food_amount_set', $input($other->id, 100, 'g'))->assertNotFound();
    builderCall($user, 'meal_food_amount_set', $input($rice->id, 100, 'cups'))->assertStatus(422)->assertJsonPath('error.violations.0.field', 'unit');
    builderCall($user, 'meal_food_amount_set', $input($rice->id, 2, 'servings'))
        ->assertStatus(422)->assertJsonPath('error.violations.0.field', 'unit')
        ->assertJsonPath('error.message', 'Servings are for a recipe; set a food in g, oz or a household unit.');
    builderCall($user, 'meal_food_amount_set', $input($rice->id, -5, 'g'))->assertStatus(422)->assertJsonPath('error.violations.0.field', 'value');
    expect($rice->fresh()->grams)->toBe(80.0);

    builderCall($user, 'meal_food_amount_set', $input($rice->id, 120, 'g'))->assertOk()->assertJsonPath('data.invalidates', builderKeys($meal));
    builderCall($user, 'meal_food_amount_set', $input($serving->id, 2, 'servings'))->assertOk();

    expect($rice->fresh()->grams)->not->toBe(80.0)
        ->and($serving->fresh()->servings)->toBe(2.0);
});

it('MEAL-004 steps an entry and answers with the meal\'s read keys', function () {
    $user = User::factory()->create();
    $meal = Seed::dinner($user);
    $rice = Seed::planFood($meal, Seed::rice(), 80, 0, locked: true);
    $other = Seed::planFood(Seed::breakfast($user), Seed::rice(), 50, 0);

    builderCall($user, 'meal_food_step', ['meal_id' => Seed::dinner(User::factory()->create()), 'entry_id' => $rice->id, 'direction' => 'up'])->assertNotFound();
    builderCall($user, 'meal_food_step', ['meal_id' => $meal, 'entry_id' => $other->id, 'direction' => 'up'])->assertNotFound();
    builderCall($user, 'meal_food_step', ['meal_id' => $meal, 'entry_id' => $rice->id, 'direction' => 'sideways'])
        ->assertStatus(422)->assertJsonPath('error.violations.0.field', 'direction');
    expect($rice->fresh()->grams)->toBe(80.0);

    builderCall($user, 'meal_food_step', ['meal_id' => $meal, 'entry_id' => $rice->id, 'direction' => 'up'])->assertOk()->assertJsonPath('data.invalidates', builderKeys($meal));

    expect($rice->fresh()->grams)->toBe(85.0);
});

it('MEAL-005 locks and unlocks an entry and answers with the meal\'s read keys', function () {
    $user = User::factory()->create();
    $meal = Seed::dinner($user);
    $rice = Seed::planFood($meal, Seed::rice(), 80, 0);
    $other = Seed::planFood(Seed::breakfast($user), Seed::rice(), 50, 0);

    builderCall($user, 'meal_food_lock_set', ['meal_id' => Seed::dinner(User::factory()->create()), 'entry_id' => $rice->id, 'locked' => true])->assertNotFound();
    builderCall($user, 'meal_food_lock_set', ['meal_id' => $meal, 'entry_id' => $other->id, 'locked' => true])->assertNotFound();
    builderCall($user, 'meal_food_lock_set', ['meal_id' => $meal, 'entry_id' => $rice->id])->assertStatus(422)->assertJsonPath('error.violations.0.field', 'locked');

    builderCall($user, 'meal_food_lock_set', ['meal_id' => $meal, 'entry_id' => $rice->id, 'locked' => true])->assertOk()->assertJsonPath('data.invalidates', builderKeys($meal));
    expect($rice->fresh()->is_locked)->toBeTrue();

    builderCall($user, 'meal_food_lock_set', ['meal_id' => $meal, 'entry_id' => $rice->id, 'locked' => false])->assertOk();
    expect($rice->fresh()->is_locked)->toBeFalse()
        ->and($rice->fresh()->grams)->toBe(80.0);
});

it('MEAL-006 rebalances the meal and answers with its read keys', function () {
    $user = User::factory()->create();
    $meal = Seed::dinner($user);
    $rice = Seed::planFood($meal, Seed::rice(), 10, 0);

    builderCall($user, 'meal_rebalance', ['meal_id' => Seed::dinner(User::factory()->create())])->assertNotFound();
    builderCall($user, 'meal_rebalance', [])->assertStatus(422)->assertJsonPath('error.violations.0.field', 'meal_id');
    expect($rice->fresh()->grams)->toBe(10.0);

    builderCall($user, 'meal_rebalance', ['meal_id' => $meal])->assertOk()->assertJsonPath('data.invalidates', builderKeys($meal));

    expect($rice->fresh()->grams)->not->toBe(10.0);
});
