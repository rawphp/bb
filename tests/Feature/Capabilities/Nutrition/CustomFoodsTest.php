<?php

use App\Capabilities\Support\HostDefinition;
use App\Models\Food;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Tests\Support\ScreenFixture;

// The HTTP seam of custom_foods_show, custom_food_save and custom_food_delete.
// The rules are unit tests (CustomFoodServingTest, SaveCustomFoodTest, DeleteCustomFoodTest, CustomFoodSaveInputTest).

afterEach(fn () => Carbon::setTestNow());

function customFoodCall(?User $user, string $name, array $input = []): TestResponse
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson("/capabilities/{$name}", $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

/** @return array<string, mixed> a valid custom_food_save input: one 30 g protein bar */
function customFoodInput(array $changes = []): array
{
    return [
        'id' => (string) Str::ulid(), 'name' => 'Protein bar', 'brand' => 'Barco', 'barcode' => '12345678905',
        'serving_size' => 1, 'serving_unit' => 'piece', 'serving_grams' => 30,
        'protein' => 6, 'fat' => 3, 'carbs' => 12, 'fiber' => 1,
        ...$changes,
    ];
}

it('FOOD-061 AGT-010 custom_food_delete is destructive and neither write is reversible', function () {
    $registry = app(CapabilityRegistry::class);

    expect(HostDefinition::isDestructive($registry->get('custom_food_delete')))->toBeTrue()
        ->and(HostDefinition::isDestructive($registry->get('custom_food_save')))->toBeFalse()
        ->and(HostDefinition::isReversible($registry->get('custom_food_delete')))->toBeFalse()
        ->and(HostDefinition::isReversible($registry->get('custom_food_save')))->toBeFalse()
        ->and(HostDefinition::affects($registry->get('custom_food_save')))->toBe(['custom_foods_show'])
        ->and(HostDefinition::affects($registry->get('custom_food_delete')))->toBe(['custom_foods_show']);
});

it('FOOD-061 refuses every custom food capability when signed out', function (string $name, array $input) {
    customFoodCall(null, $name, $input)->assertUnauthorized();
})->with([
    'custom_foods_show' => ['custom_foods_show', []],
    'custom_food_save' => ['custom_food_save', ['id' => '01JBX0A1B2C3D4E5F6G7H8J9K0']],
    'custom_food_delete' => ['custom_food_delete', ['food_id' => '01JBX0A1B2C3D4E5F6G7H8J9K0']],
]);

it('FOOD-061 lists the user\'s custom foods by name, with the values the edit form opens with', function () {
    Carbon::setTestNow('2026-11-03 09:00:00');
    $user = User::factory()->create();
    Food::factory()->custom($user)->create(['name' => 'Protein pancakes', 'brand' => 'Myprotein', 'barcode' => '5055534301234',
        'custom_serving' => ['size' => 100, 'unit' => 'g', 'grams' => 100, 'protein' => 25, 'fat' => 4, 'carbs' => 19, 'fiber' => 2]]);
    $granola = Food::factory()->custom($user)->create(['name' => 'Mum\'s granola',
        'custom_serving' => ['size' => 1, 'unit' => 'cup', 'grams' => 95, 'protein' => 10, 'fat' => 16, 'carbs' => 58, 'fiber' => 6]]);
    Food::factory()->custom($user)->create(['name' => 'Deleted bar'])->delete();
    Food::factory()->custom(User::factory()->create())->create(['name' => 'Another user\'s bar']);
    Food::factory()->create(['name' => 'Apple']);

    $response = customFoodCall($user, 'custom_foods_show')->assertOk();

    expect(array_column($response->json('data.foods'), 'name'))->toBe(['Mum\'s granola', 'Protein pancakes'])
        ->and($response->json('data.foods.0'))->toMatchArray([
            'food_id' => $granola->id, 'detail' => '1 cup · 416 kcal', 'brand' => null, 'barcode' => null,
            'serving_size' => 1, 'serving_unit' => 'cup', 'serving_grams' => 95,
            'protein' => 10, 'fat' => 16, 'carbs' => 58, 'fiber' => 6,
        ])
        ->and($response->json('data.empty_text'))->toBeNull();
    assertMatchesScreenFixture('custom_foods_show', $response->json('data'));
});

it('FOOD-061 says how to make a custom food when the user has none', function () {
    Carbon::setTestNow('2026-11-03 09:00:00');

    $response = customFoodCall(User::factory()->create(), 'custom_foods_show')
        ->assertOk()
        ->assertJsonPath('data.foods', [])
        ->assertJsonPath('data.empty_text', 'No custom foods yet. Create one from the Pantry.');

    // assertMatchesScreenFixture reads a state's schema by its own name, so the state's shape is checked here.
    assertMatchesScreenFixture('custom_foods_show', $response->json('data'));
    expect(ScreenFixture::differences(ScreenFixture::load('custom_foods_show.empty'), $response->json('data')))->toBe([]);
});

it('FOOD-060 creates a custom food under the client\'s id and says the list changed', function () {
    $user = User::factory()->create();
    $input = customFoodInput();

    customFoodCall($user, 'custom_food_save', $input)
        ->assertOk()
        ->assertJsonPath('data.invalidates', ['custom_foods_show{}']);

    expect(Food::query()->findOrFail($input['id']))->user_id->toBe($user->id)->name->toBe('Protein bar')->kcal->toBe(330.0)
        ->and(customFoodCall($user, 'custom_foods_show')->json('data.foods.0.detail'))->toBe('1 piece · 99 kcal');
});

it('FOOD-060 edits the user\'s own custom food under its id', function () {
    $user = User::factory()->create();
    $food = Food::factory()->custom($user)->create();

    customFoodCall($user, 'custom_food_save', customFoodInput(['id' => $food->id, 'name' => 'Protein bar XL']))->assertOk();

    expect($food->fresh()->name)->toBe('Protein bar XL')
        ->and(Food::query()->count())->toBe(1);
});

it('FOOD-060 answers 404 for another user\'s food, a library food or a deleted food\'s id', function () {
    $user = User::factory()->create();
    $theirs = Food::factory()->custom(User::factory()->create())->create(['name' => 'Their bar']);
    $library = Food::factory()->create(['name' => 'Apple']);
    $deleted = Food::factory()->custom($user)->create(['name' => 'Old bar']);
    $deleted->delete();

    foreach ([$theirs, $library, $deleted] as $food) {
        customFoodCall($user, 'custom_food_save', customFoodInput(['id' => $food->id]))->assertNotFound();
    }

    expect($theirs->fresh()->name)->toBe('Their bar')->and($library->fresh()->name)->toBe('Apple');
});

it('FOOD-060 answers 422 naming the macro that weighs more than the serving', function () {
    customFoodCall(User::factory()->create(), 'custom_food_save', customFoodInput(['protein' => 31]))
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'protein');

    expect(Food::query()->count())->toBe(0);
});

it('FOOD-061 deletes the user\'s custom food and says the list changed', function () {
    $user = User::factory()->create();
    $food = Food::factory()->custom($user)->create();

    customFoodCall($user, 'custom_food_delete', ['food_id' => $food->id])
        ->assertOk()
        ->assertJsonPath('data.invalidates', ['custom_foods_show{}']);

    expect($food->fresh()->trashed())->toBeTrue()
        ->and(customFoodCall($user, 'custom_foods_show')->json('data.foods'))->toBe([]);
});

it('FOOD-061 answers 404 for deleting another user\'s custom food or a library food', function () {
    $user = User::factory()->create();
    $theirs = Food::factory()->custom(User::factory()->create())->create();
    $library = Food::factory()->create();

    customFoodCall($user, 'custom_food_delete', ['food_id' => $theirs->id])->assertNotFound();
    customFoodCall($user, 'custom_food_delete', ['food_id' => $library->id])->assertNotFound();

    expect($theirs->fresh()->trashed())->toBeFalse();
});

it('FOOD-061 answers 422 naming food_id when it is missing', function () {
    customFoodCall(User::factory()->create(), 'custom_food_delete', [])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'food_id');
});
