<?php

use App\Models\Food;
use App\Models\PlannedFood;
use App\Models\PlannedRecipe;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\MealBuilderSeed as Seed;

// The HTTP seam of meal_food_add_show (FOOD-030): the Pantry's basket as meal_food_add would add it, read and not
// saved. The wording is BasketPayloadTest's.

beforeEach(fn () => Carbon::setTestNow('2026-10-13 09:00:00'));

afterEach(fn () => Carbon::setTestNow());

function basketCall(?User $user, string $name, array $input): TestResponse
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson("/capabilities/{$name}", $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

it('FOOD-030 refuses meal_food_add_show when signed out', function () {
    basketCall(null, 'meal_food_add_show', ['meal_id' => '0190a000-0000-7000-8000-000000000000', 'entries' => [['id' => (string) Str::ulid(), 'food_id' => '01JBX0A1B2C3D4E5F6G7H8J9K1']]])
        ->assertUnauthorized();
});

it('FOOD-030 shows the amounts meal_food_add then plans, saving nothing until it runs', function () {
    $user = User::factory()->create();
    $meal = Seed::dinner($user);
    Seed::planFood($meal, Seed::oil(), 10, 0, locked: true);
    $entries = [
        ['id' => strtolower((string) Str::ulid()), 'food_id' => Seed::chicken()->id, 'weighing' => 'cooked'],
        ['id' => strtolower((string) Str::ulid()), 'food_id' => Seed::rice()->id],
        ['id' => strtolower((string) Str::ulid()), 'recipe_id' => Seed::stew()->id],
    ];

    $data = basketCall($user, 'meal_food_add_show', ['meal_id' => $meal, 'entries' => $entries])->assertOk()->json('data');

    assertMatchesScreenFixture('meal_food_add_show', $data);
    expect(PlannedFood::query()->count())->toBe(1)
        ->and(PlannedRecipe::query()->count())->toBe(0)
        ->and(array_column($data['entries'], 'id'))->toBe(array_column($entries, 'id'));

    basketCall($user, 'meal_food_add', ['meal_id' => $meal, 'entries' => $entries])->assertOk();
    $shown = basketCall($user, 'meal_show', ['meal_id' => $meal])->assertOk()->json('data');
    $planned = array_column($shown['entries'], 'amount_text', 'id');

    foreach ($data['entries'] as $entry) {
        expect($entry['amount_text'])->toBe($planned[$entry['id']]);
    }
    expect($data['bars'])->toBe($shown['bars'])
        ->and($data['targets_met'])->toBe($shown['targets_met'])
        ->and($data['hints'])->toBe($shown['hints']);
});

it('FOOD-030 FOOD-028 gives no amount to a food the meal already holds, as Add skips it', function () {
    $user = User::factory()->create();
    $meal = Seed::dinner($user);
    $rice = Seed::rice();
    Seed::planFood($meal, $rice, 150, 0);
    $entry = ['id' => (string) Str::ulid(), 'food_id' => $rice->id];

    $data = basketCall($user, 'meal_food_add_show', ['meal_id' => $meal, 'entries' => [$entry]])->assertOk()->json('data');

    expect($data['entries'])->toBe([['id' => $entry['id'], 'amount_text' => null]]);
});

it('FOOD-030 CAP-020 answers 404 for another user\'s meal or a food or recipe the user cannot see, and 422 for a bad entry', function () {
    $user = User::factory()->create();
    $mine = Seed::dinner($user);
    $theirs = Seed::dinner(User::factory()->create());
    $custom = Food::factory()->create(['user_id' => User::factory()->create()->id]);
    $entry = fn (array $extra) => ['id' => (string) Str::ulid()] + $extra;

    basketCall($user, 'meal_food_add_show', ['meal_id' => $theirs, 'entries' => [$entry(['food_id' => Seed::rice()->id])]])->assertNotFound();
    basketCall($user, 'meal_food_add_show', ['meal_id' => $mine, 'entries' => [$entry(['food_id' => $custom->id])]])->assertNotFound();
    basketCall($user, 'meal_food_add_show', ['meal_id' => $mine, 'entries' => [$entry(['recipe_id' => Seed::stew(User::factory()->create())->id])]])->assertNotFound();
    basketCall($user, 'meal_food_add_show', ['meal_id' => $mine, 'entries' => [$entry(['food_id' => Seed::rice()->id, 'recipe_id' => Seed::stew()->id])]])->assertStatus(422);
    basketCall($user, 'meal_food_add_show', ['meal_id' => $mine, 'entries' => []])->assertStatus(422);
});
