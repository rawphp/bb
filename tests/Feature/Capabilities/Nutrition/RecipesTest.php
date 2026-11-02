<?php

use App\Ai\RecipePage;
use App\Capabilities\Support\HostDefinition;
use App\Models\Food;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Rawphp\Capabilities\Registry\CapabilityRegistry;
use Rawphp\Capabilities\Schema\JsonSchemaValidator;
use Tests\Support\RecordingLlmClient;
use Tests\Support\ScreenFixture;

// The HTTP seam of recipes_show, recipe_show, recipe_save, recipe_delete, recipe_generate and recipe_import. The rules
// are unit tests (RecipeTextTest, RecipeEditTest, SaveRecipeTest, DeleteRecipeTest, DraftRecipeTest, RecipeDraftsTest).

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-11-03 09:00:00');
});

afterEach(fn () => Carbon::setTestNow());

function recipeCall(?User $user, string $name, array $input = []): TestResponse
{
    $request = $user === null ? test() : test()->actingAs($user);

    return $request->postJson("/capabilities/{$name}", $input, ['Idempotency-Key' => (string) Str::ulid()]);
}

/**
 * A recipe with these foods at these grams, in order.
 *
 * @param  list<array{0: Food, 1: float, 2?: bool}>  $lines
 */
function recipeWith(?User $owner, string $name, string $mealType, float $servings, array $lines, array $steps = []): Recipe
{
    $recipe = Recipe::factory()->create(['user_id' => $owner?->id, 'name' => $name, 'meal_type' => $mealType, 'servings' => $servings, 'instructions' => $steps]);
    foreach ($lines as $position => $line) {
        RecipeIngredient::factory()->create(['recipe_id' => $recipe->id, 'food_id' => $line[0]->id, 'position' => $position, 'grams' => $line[1], 'is_cooked' => $line[2] ?? false]);
    }

    return $recipe;
}

/** @return array<string, mixed> a valid recipe_save input: a new two-serving chicken plate */
function recipeInput(Food $food, array $changes = []): array
{
    return [
        'id' => (string) Str::ulid(), 'name' => 'Chicken plate', 'servings' => 2, 'meal_type' => 'lunch',
        'ingredients' => [['food_id' => $food->id, 'grams' => 300, 'is_cooked' => false]],
        'steps' => ['Grill the chicken.'],
        ...$changes,
    ];
}

function recipeChickenFood(): Food
{
    return Food::factory()->create(['name' => 'Chicken breast', 'kcal' => 120, 'protein' => 23, 'fat' => 2, 'carbs' => 0, 'cooked_ratio' => 0.75]);
}

function recipeBeansFood(): Food
{
    return Food::factory()->create(['name' => 'Black beans', 'kcal' => 130, 'protein' => 9, 'fat' => 0.5, 'carbs' => 24, 'vegan' => true]);
}

/** @param  array<string, mixed>  $data */
function expectRecipeSchema(string $name, array $data): void
{
    $schema = json_decode((string) file_get_contents(base_path("tests/fixtures/schemas/{$name}.output.json")), true);
    expect((new JsonSchemaValidator)->validate($schema, $data))->toBe([], "{$name} breaks its output schema");
}

it('CAP-011 AGT-010 RCP-021 each recipe write names the reads it changes, and only deleting is destructive', function () {
    $registry = app(CapabilityRegistry::class);

    expect(HostDefinition::affects($registry->get('recipe_save')))->toBe(['recipes_show', 'recipe_show', 'today_show'])
        ->and(HostDefinition::affects($registry->get('recipe_delete')))->toBe(['recipes_show', 'recipe_show'])
        ->and(HostDefinition::isDestructive($registry->get('recipe_delete')))->toBeTrue()
        ->and(HostDefinition::isDestructive($registry->get('recipe_save')))->toBeFalse()
        ->and(HostDefinition::isReversible($registry->get('recipe_save')))->toBeFalse()
        ->and(HostDefinition::isReversible($registry->get('recipe_delete')))->toBeFalse();

    foreach (['recipes_show', 'recipe_show', 'recipe_generate', 'recipe_import'] as $read) {
        expect($registry->get($read)->readOnly)->toBeTrue("{$read} changes nothing");
    }
});

it('RCP-001 refuses every recipe capability when signed out', function (string $name, array $input) {
    recipeCall(null, $name, $input)->assertUnauthorized();
})->with([
    'recipes_show' => ['recipes_show', []],
    'recipe_show' => ['recipe_show', ['recipe_id' => '01JBX0A1B2C3D4E5F6G7H8J9K0']],
    'recipe_save' => ['recipe_save', ['id' => '01JBX0A1B2C3D4E5F6G7H8J9K0']],
    'recipe_delete' => ['recipe_delete', ['recipe_id' => '01JBX0A1B2C3D4E5F6G7H8J9K0']],
    'recipe_generate' => ['recipe_generate', ['protein' => 40, 'fat' => 15, 'carbs' => 60, 'meal_type' => 'dinner', 'complexity' => 'simple']],
    'recipe_import' => ['recipe_import', ['url' => 'https://recipes.example/bowl']],
]);

// recipes_show

it('RCP-020 the library lists the ready-made recipes by name, each with one serving\'s macros', function () {
    $oats = Food::factory()->create(['name' => 'Oats', 'kcal' => 380, 'protein' => 13, 'fat' => 7, 'carbs' => 60]);
    $chicken = recipeChickenFood();
    recipeWith(null, 'Turkey chili', 'dinner', 4, [[$chicken, 600], [recipeBeansFood(), 240]]);
    recipeWith(null, 'Overnight oats', 'breakfast', 2, [[$oats, 160]]);
    recipeWith(User::factory()->create(), 'Someone\'s bowl', 'dinner', 1, [[$chicken, 200]]);

    $response = recipeCall(User::factory()->create(), 'recipes_show', ['segment' => 'library'])->assertOk();

    expect($response->json('data.rows'))->toBe([
        ['recipe_id' => Recipe::query()->where('name', 'Overnight oats')->value('id'), 'name' => 'Overnight oats', 'meal_type_title' => 'Breakfast', 'macros_text' => '304 kcal · P 10 · F 6 · C 48', 'is_library' => true],
        ['recipe_id' => Recipe::query()->where('name', 'Turkey chili')->value('id'), 'name' => 'Turkey chili', 'meal_type_title' => 'Dinner', 'macros_text' => '258 kcal · P 40 · F 3 · C 14', 'is_library' => true],
    ])
        ->and($response->json('data.empty_text'))->toBeNull();
    assertMatchesScreenFixture('recipes_show', $response->json('data'));
});

it('RCP-001 RCP-020 My recipes lists only the user\'s own, and is the list shown first', function () {
    $user = User::factory()->create();
    $chicken = recipeChickenFood();
    $mine = recipeWith($user, 'Chicken bowl', 'dinner', 2, [[$chicken, 400]]);
    recipeWith($user, 'Old bowl', 'dinner', 2, [[$chicken, 400]])->delete();
    recipeWith(null, 'Library bowl', 'dinner', 2, [[$chicken, 400]]);
    recipeWith(User::factory()->create(), 'Their bowl', 'dinner', 2, [[$chicken, 400]]);

    $response = recipeCall($user, 'recipes_show')->assertOk();

    expect($response->json('data.rows'))->toBe([
        ['recipe_id' => $mine->id, 'name' => 'Chicken bowl', 'meal_type_title' => 'Dinner', 'macros_text' => '240 kcal · P 46 · F 4 · C 0', 'is_library' => false],
    ]);
    assertMatchesScreenFixture('recipes_show', $response->json('data'));
});

it('RCP-020 a meal type and diet filters narrow either list', function () {
    $user = User::factory()->create();
    $beans = recipeBeansFood();
    $chicken = recipeChickenFood();
    recipeWith($user, 'Bean bowl', 'lunch', 1, [[$beans, 200]])->forceFill(['vegan' => true])->save();
    recipeWith($user, 'Chicken lunch', 'lunch', 1, [[$chicken, 200]]);
    recipeWith($user, 'Bean dinner', 'dinner', 1, [[$beans, 200]])->forceFill(['vegan' => true])->save();
    recipeWith(null, 'Library beans', 'lunch', 1, [[$beans, 200]])->forceFill(['vegan' => true])->save();

    $names = fn (array $input) => array_column(recipeCall($user, 'recipes_show', $input)->assertOk()->json('data.rows'), 'name');

    expect($names(['meal_type' => 'lunch']))->toBe(['Bean bowl', 'Chicken lunch'])
        ->and($names(['meal_type' => 'lunch', 'diets' => ['vegan']]))->toBe(['Bean bowl'])
        ->and($names(['diets' => ['vegan']]))->toBe(['Bean bowl', 'Bean dinner'])
        ->and($names(['segment' => 'library', 'meal_type' => 'lunch', 'diets' => ['vegan']]))->toBe(['Library beans'])
        ->and($names(['segment' => 'library', 'meal_type' => 'dinner']))->toBe([]);
});

it('RCP-020 an empty list says what to do', function () {
    $user = User::factory()->create();

    $response = recipeCall($user, 'recipes_show')->assertOk();

    expect($response->json('data'))->toBe(['rows' => [], 'empty_text' => 'No recipes yet. Create one, or import one from a web page.'])
        ->and(ScreenFixture::differences(ScreenFixture::load('recipes_show.empty'), $response->json('data')))->toBe([])
        ->and(recipeCall($user, 'recipes_show', ['meal_type' => 'snack'])->json('data.empty_text'))->toBe('No recipes match these filters.')
        ->and(recipeCall($user, 'recipes_show', ['segment' => 'library'])->json('data.empty_text'))->toBe('No library recipes match these filters.');
    assertMatchesScreenFixture('recipes_show', $response->json('data'));
});

it('RCP-020 answers 422 naming the field for an unknown segment', function () {
    recipeCall(User::factory()->create(), 'recipes_show', ['segment' => 'theirs'])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'segment');
});

// recipe_show

it('RCP-001 the user\'s recipe shows its servings, one serving\'s macros, its ingredients and its steps', function () {
    $user = User::factory()->create();
    $chicken = recipeChickenFood();
    $beans = recipeBeansFood();
    $recipe = recipeWith($user, 'Chicken burrito bowl', 'dinner', 4, [[$chicken, 600], [$beans, 240]], ['Cook the rice.', 'Grill the chicken and slice it.', 'Build the bowls.']);
    $lines = $recipe->ingredients()->pluck('id')->all();

    $response = recipeCall($user, 'recipe_show', ['recipe_id' => $recipe->id])->assertOk();

    expect($response->json('data'))->toBe([
        'recipe_id' => $recipe->id,
        'name' => 'Chicken burrito bowl',
        'is_read_only' => false,
        'can_delete' => true,
        'servings' => 4,
        'servings_text' => '4 servings',
        'meal_type' => 'dinner',
        'meal_type_title' => 'Dinner',
        'per_serving_text' => '258 kcal · P 40 g · F 3 g · C 14 g',
        'ingredients' => [
            ['id' => (string) $lines[0], 'food_id' => $chicken->id, 'name' => 'Chicken breast', 'grams' => 600, 'amount_text' => '600 g raw', 'is_cooked' => false, 'can_toggle_cooked' => true],
            ['id' => (string) $lines[1], 'food_id' => $beans->id, 'name' => 'Black beans', 'grams' => 240, 'amount_text' => '240 g', 'is_cooked' => false, 'can_toggle_cooked' => false],
        ],
        'steps' => ['Cook the rice.', 'Grill the chicken and slice it.', 'Build the bowls.'],
    ]);
    assertMatchesScreenFixture('recipe_show', $response->json('data'));
});

it('FOOD-006 RCP-001 a cooked ingredient counts its raw weight and says it is cooked', function () {
    $user = User::factory()->create();
    $recipe = recipeWith($user, 'Chicken', 'dinner', 1, [[recipeChickenFood(), 450, true]]);

    $data = recipeCall($user, 'recipe_show', ['recipe_id' => $recipe->id])->assertOk()->json('data');

    expect($data['ingredients'][0]['amount_text'])->toBe('450 g cooked')
        ->and($data['ingredients'][0]['is_cooked'])->toBeTrue()
        ->and($data['per_serving_text'])->toBe('720 kcal · P 138 g · F 12 g · C 0 g')
        ->and($data['servings_text'])->toBe('1 serving');
});

it('RCP-020 a library recipe opens read-only and cannot be deleted', function () {
    $recipe = recipeWith(null, 'Overnight oats', 'breakfast', 2, [[recipeBeansFood(), 100]]);

    $data = recipeCall(User::factory()->create(), 'recipe_show', ['recipe_id' => $recipe->id])->assertOk()->json('data');

    expect($data['is_read_only'])->toBeTrue()
        ->and($data['can_delete'])->toBeFalse();
    assertMatchesScreenFixture('recipe_show', $data);
});

it('RCP-001 answers 404 for another user\'s recipe or a deleted one', function () {
    $user = User::factory()->create();
    $theirs = recipeWith(User::factory()->create(), 'Their bowl', 'dinner', 1, [[recipeBeansFood(), 100]]);
    $deleted = recipeWith($user, 'Old bowl', 'dinner', 1, [[recipeBeansFood(), 100]]);
    $deleted->delete();

    recipeCall($user, 'recipe_show', ['recipe_id' => $theirs->id])->assertNotFound();
    recipeCall($user, 'recipe_show', ['recipe_id' => $deleted->id])->assertNotFound();
});

it('RCP-001 answers 422 naming recipe_id when it is missing', function () {
    recipeCall(User::factory()->create(), 'recipe_show', [])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'recipe_id');
});

// recipe_save

it('RCP-001 creates a recipe under the client\'s id and says which reads changed', function () {
    $user = User::factory()->create();
    $chicken = recipeChickenFood();
    $input = recipeInput($chicken);

    recipeCall($user, 'recipe_save', $input)
        ->assertOk()
        ->assertJsonPath('data.invalidates', [
            'recipes_show{}',
            'recipes_show{"segment":"mine"}',
            'recipe_show{"recipe_id":"'.$input['id'].'"}',
            'today_show{}',
        ]);

    expect(Recipe::query()->findOrFail($input['id']))->user_id->toBe($user->id)->name->toBe('Chicken plate')
        ->and(recipeCall($user, 'recipe_show', ['recipe_id' => $input['id']])->json('data.per_serving_text'))->toBe('180 kcal · P 35 g · F 3 g · C 0 g');
});

it('RCP-001 a recipe may use the user\'s own custom food', function () {
    $user = User::factory()->create();
    $bar = Food::factory()->custom($user)->create();

    recipeCall($user, 'recipe_save', recipeInput($bar))->assertOk();
});

it('RCP-021 edits the user\'s own recipe under its id', function () {
    $user = User::factory()->create();
    $chicken = recipeChickenFood();
    $recipe = recipeWith($user, 'Chicken plate', 'lunch', 2, [[$chicken, 300]]);

    recipeCall($user, 'recipe_save', recipeInput($chicken, ['id' => $recipe->id, 'name' => 'Chicken plate XL', 'servings' => 3]))->assertOk();

    expect($recipe->fresh())->name->toBe('Chicken plate XL')->servings->toBe(3.0)
        ->and(Recipe::query()->count())->toBe(1);
});

it('RCP-001 RCP-020 answers 404 for saving over a library recipe, another user\'s recipe or a deleted one', function () {
    $user = User::factory()->create();
    $chicken = recipeChickenFood();
    $library = recipeWith(null, 'Library plate', 'lunch', 2, [[$chicken, 300]]);
    $theirs = recipeWith(User::factory()->create(), 'Their plate', 'lunch', 2, [[$chicken, 300]]);
    $deleted = recipeWith($user, 'Old plate', 'lunch', 2, [[$chicken, 300]]);
    $deleted->delete();

    foreach ([$library, $theirs, $deleted] as $recipe) {
        recipeCall($user, 'recipe_save', recipeInput($chicken, ['id' => $recipe->id, 'name' => 'Mine now']))->assertNotFound();
    }

    expect($library->fresh()->name)->toBe('Library plate')->and($theirs->fresh()->name)->toBe('Their plate');
});

it('RCP-001 answers 404 for an ingredient that is another user\'s custom food', function () {
    $user = User::factory()->create();
    $theirs = Food::factory()->custom(User::factory()->create())->create();

    recipeCall($user, 'recipe_save', recipeInput($theirs))->assertNotFound();

    expect(Recipe::query()->count())->toBe(0);
});

it('RCP-001 answers 422 naming the field for servings of 0', function () {
    recipeCall(User::factory()->create(), 'recipe_save', recipeInput(recipeChickenFood(), ['servings' => 0]))
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'servings');

    expect(Recipe::query()->count())->toBe(0);
});

// recipe_delete

it('RCP-021 deletes the user\'s recipe and says the lists changed', function () {
    $user = User::factory()->create();
    $recipe = recipeWith($user, 'Chicken plate', 'lunch', 2, [[recipeChickenFood(), 300]]);

    recipeCall($user, 'recipe_delete', ['recipe_id' => $recipe->id])
        ->assertOk()
        ->assertJsonPath('data.invalidates', [
            'recipes_show{}',
            'recipes_show{"segment":"mine"}',
            'recipe_show{"recipe_id":"'.$recipe->id.'"}',
        ]);

    expect($recipe->fresh()->trashed())->toBeTrue()
        ->and(recipeCall($user, 'recipes_show')->json('data.rows'))->toBe([]);
});

it('RCP-020 answers 404 for deleting a library recipe or another user\'s recipe', function () {
    $user = User::factory()->create();
    $library = recipeWith(null, 'Library plate', 'lunch', 2, [[recipeChickenFood(), 300]]);
    $theirs = recipeWith(User::factory()->create(), 'Their plate', 'lunch', 2, [[recipeChickenFood(), 300]]);

    recipeCall($user, 'recipe_delete', ['recipe_id' => $library->id])->assertNotFound();
    recipeCall($user, 'recipe_delete', ['recipe_id' => $theirs->id])->assertNotFound();

    expect($library->fresh()->trashed())->toBeFalse()->and($theirs->fresh()->trashed())->toBeFalse();
});

it('RCP-021 answers 422 naming recipe_id when it is not a string', function () {
    recipeCall(User::factory()->create(), 'recipe_delete', ['recipe_id' => 12])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'recipe_id');
});

// recipe_generate and recipe_import take no row id, so they have no 404.

it('RCP-010 generates a draft from the brief, its items matched to foods, at the meal type asked for', function () {
    $chicken = Food::factory()->create(['name' => 'Chicken breast', 'curated' => true]);
    RecordingLlmClient::answering([
        'name' => 'Lemon chicken', 'servings' => 2, 'instructions' => ['Roast.'],
        'items' => [
            ['name' => 'Chicken', 'search' => 'chicken breast', 'grams' => 400],
            ['name' => 'Sumac', 'search' => 'zzzz', 'grams' => 5],
        ],
    ])->install();

    $data = recipeCall(User::factory()->create(), 'recipe_generate', ['protein' => 68, 'fat' => 28, 'carbs' => 128, 'meal_type' => 'dinner', 'complexity' => 'moderate'])
        ->assertOk()->json('data');

    expect($data)->toEqual([
        'name' => 'Lemon chicken', 'servings' => 2, 'meal_type' => 'dinner', 'steps' => ['Roast.'],
        'items' => [
            ['name' => 'Chicken', 'grams' => 400, 'food_id' => $chicken->id, 'match_text' => 'Chicken breast', 'detail' => null],
            ['name' => 'Sumac', 'grams' => 5, 'food_id' => null, 'match_text' => 'No match', 'detail' => null],
        ],
    ]);
    expectRecipeSchema('recipe_generate', $data);
});

it('RCP-010 answers 422 naming the field for an unknown complexity, and asks nothing', function () {
    $llm = RecordingLlmClient::answering([])->install();

    recipeCall(User::factory()->create(), 'recipe_generate', ['protein' => 68, 'fat' => 28, 'carbs' => 128, 'meal_type' => 'dinner', 'complexity' => 'gourmet'])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'complexity');

    expect($llm->calls)->toBe([]);
});

it('RCP-003 imports a draft from a web page with no meal type', function () {
    app()->bind(RecipePage::class, fn () => new RecipePage(fn () => ['93.184.216.34']));
    $flour = Food::factory()->create(['name' => 'Flour', 'curated' => true]);
    Http::fake(['recipes.example/*' => Http::response('<p>Pancakes: 200 g flour</p>')]);
    RecordingLlmClient::answering([
        'name' => 'Pancakes', 'servings' => 4, 'instructions' => ['Mix.', 'Fry.'],
        'items' => [['name' => 'Flour', 'search' => 'flour', 'grams' => 200]],
    ])->install();

    $data = recipeCall(User::factory()->create(), 'recipe_import', ['url' => 'https://recipes.example/pancakes'])->assertOk()->json('data');

    expect($data)->toEqual([
        'name' => 'Pancakes', 'servings' => 4, 'meal_type' => null, 'steps' => ['Mix.', 'Fry.'],
        'items' => [['name' => 'Flour', 'grams' => 200, 'food_id' => $flour->id, 'match_text' => 'Flour', 'detail' => null]],
    ]);
    expectRecipeSchema('recipe_import', $data);
});

it('RCP-003 a page that cannot be fetched is refused in plain words', function () {
    app()->bind(RecipePage::class, fn () => new RecipePage(fn () => ['127.0.0.1']));
    $llm = RecordingLlmClient::answering([])->install();

    recipeCall(User::factory()->create(), 'recipe_import', ['url' => 'http://localhost/secret'])
        ->assertStatus(422)
        ->assertJsonPath('error.message', 'We couldn\'t fetch that page.');

    expect($llm->calls)->toBe([]);
});

it('RCP-003 answers 422 naming url when it is not a web address', function () {
    recipeCall(User::factory()->create(), 'recipe_import', ['url' => 'not a url'])
        ->assertStatus(422)
        ->assertJsonPath('error.violations.0.field', 'url');
});
