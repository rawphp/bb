<?php

use App\Ai\AiRecipe;
use App\Ai\FoodAi;
use App\Ai\PageUnavailable;
use App\Ai\RecipeBrief;
use App\Ai\RecipePage;
use App\Domain\Model\DietFilter;
use App\Models\Food;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\RecordingLlmClient;

const PLATE_JPEG = 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==';

beforeEach(fn () => Http::preventStrayRequests());

function aiFood(string $name, array $attrs = []): Food
{
    return Food::factory()->create(['name' => $name, 'curated' => true, ...$attrs]);
}

/**
 * Run the call and return every SQL statement it wrote, so a test can prove nothing was saved.
 *
 * @return list<string>
 */
function writesDuring(Closure $call): array
{
    $writes = [];
    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });
    $call();

    return $writes;
}

function recipeBrief(array $overrides = []): RecipeBrief
{
    return new RecipeBrief(...$overrides + [
        'protein' => 40.0, 'fat' => 15.0, 'carbs' => 60.0, 'kcal' => 560.0,
        'mealType' => 'dinner', 'complexity' => 'simple',
        'ingredients' => ['chicken', 'rice'], 'preferences' => 'spicy',
        'diets' => [DietFilter::DairyFree],
    ]);
}

it('FOOD-043 sends the plate photo and returns its ingredients matched to database foods', function () {
    $user = User::factory()->create();
    $chicken = aiFood('Chicken breast');
    $llm = RecordingLlmClient::answering(['items' => [
        ['name' => 'Grilled chicken', 'search' => 'chicken breast', 'grams' => 150.04],
        ['name' => 'Mystery', 'search' => 'zzzz', 'grams' => 20],
    ]])->install();

    $items = app(FoodAi::class)->photo($user, PLATE_JPEG);

    expect($items)->toHaveCount(2)
        ->and($items[0]->name)->toBe('Grilled chicken')
        ->and($items[0]->grams)->toBe(150.0)
        ->and($items[0]->food?->id)->toBe($chicken->id)
        ->and($items[1]->food)->toBeNull()
        ->and($llm->userContent()[0]['type'])->toBe('image')
        ->and($llm->userContent()[0]['source']['media_type'])->toBe('image/jpeg')
        ->and($llm->userContent()[1])->toBe(['type' => 'text', 'text' => 'List the ingredients on this plate with estimated grams.'])
        ->and($llm->system())->toStartWith(file_get_contents(resource_path('prompts/photo.md')));
});

it('FOOD-043 returns no items for a photo with no food', function () {
    RecordingLlmClient::answering(['items' => []])->install();

    expect(app(FoodAi::class)->photo(User::factory()->create(), PLATE_JPEG))->toBe([]);
});

it('FOOD-044 sends the typed description and returns its ingredients matched to database foods', function () {
    $user = User::factory()->create();
    aiFood('Chicken breast');
    $llm = RecordingLlmClient::answering(['items' => [['name' => 'Chicken', 'search' => 'chicken breast', 'grams' => 120]]])->install();

    $items = app(FoodAi::class)->describe($user, 'chicken burrito bowl with rice');

    expect($items[0]->food?->name)->toBe('Chicken breast')
        ->and($items[0]->grams)->toBe(120.0)
        ->and($llm->userContent())->toBe([['type' => 'text', 'text' => 'chicken burrito bowl with rice']])
        ->and($llm->system())->toStartWith(file_get_contents(resource_path('prompts/describe.md')));
});

it('FOOD-043 FOOD-044 refuses an item with negative grams', function () {
    RecordingLlmClient::answering(['items' => [['name' => 'Chicken', 'search' => 'chicken', 'grams' => -5]]])->install();

    expect(fn () => app(FoodAi::class)->describe(User::factory()->create(), 'chicken'))->toThrow(App\Ai\AiFailed::class);
});

it('RCP-010 generates a recipe from the brief and matches its foods under the diet filters', function () {
    $user = User::factory()->create();
    aiFood('Chicken breast', ['dairy_free' => true]);
    aiFood('Chicken parmesan', ['dairy_free' => false]);
    aiFood('Rice', ['dairy_free' => true]);
    aiFood('Cheddar cheese', ['dairy_free' => false]);
    $llm = RecordingLlmClient::answering([
        'name' => 'Spicy chicken rice', 'servings' => 2, 'instructions' => ['Cook rice.', 'Fry chicken.'],
        'items' => [
            ['name' => 'Chicken', 'search' => 'chicken', 'grams' => 300],
            ['name' => 'Rice', 'search' => 'rice', 'grams' => 150],
            ['name' => 'Cheese', 'search' => 'cheddar', 'grams' => 20],
        ],
    ])->install();

    $recipe = app(FoodAi::class)->recipe($user, recipeBrief());

    expect($recipe)->toBeInstanceOf(AiRecipe::class)
        ->and($recipe->name)->toBe('Spicy chicken rice')
        ->and($recipe->servings)->toBe(2)
        ->and($recipe->steps)->toBe(['Cook rice.', 'Fry chicken.'])
        ->and($recipe->items[0]->food?->name)->toBe('Chicken breast')
        ->and($recipe->items[1]->food?->name)->toBe('Rice')
        ->and($recipe->items[2]->food)->toBeNull(); // the only cheddar is not dairy free

    $brief = json_decode($llm->userContent()[0]['text'], true);
    expect($brief)->toBe([
        'target_macros_per_serving' => ['protein' => 40, 'fat' => 15, 'carbs' => 60, 'kcal' => 560],
        'meal_type' => 'dinner',
        'ingredients_to_use' => ['chicken', 'rice'],
        'complexity' => 'simple',
        'diet_filters' => ['dairy_free'],
        'preferences' => 'spicy',
    ])
        ->and($llm->system())->toStartWith(file_get_contents(resource_path('prompts/recipe.md')))
        ->and($llm->calls[0]['tools'][0]['input_schema']['required'])->toBe(['name', 'servings', 'instructions', 'items']);
});

it('RCP-010 sends only what the brief gives', function () {
    $llm = RecordingLlmClient::answering(['name' => 'Bowl', 'servings' => 1, 'instructions' => [], 'items' => []])->install();

    app(FoodAi::class)->recipe(User::factory()->create(), recipeBrief(['kcal' => null, 'ingredients' => [], 'preferences' => null, 'diets' => []]));

    expect(json_decode($llm->userContent()[0]['text'], true))->toBe([
        'target_macros_per_serving' => ['protein' => 40, 'fat' => 15, 'carbs' => 60],
        'meal_type' => 'dinner',
        'complexity' => 'simple',
    ]);
});

it('RCP-010 refuses a recipe of no servings', function () {
    RecordingLlmClient::answering(['name' => 'Bowl', 'servings' => 0, 'instructions' => [], 'items' => []])->install();

    expect(fn () => app(FoodAi::class)->recipe(User::factory()->create(), recipeBrief()))->toThrow(App\Ai\AiFailed::class);
});

it('RCP-003 imports a recipe page and matches its ingredients', function () {
    app()->bind(RecipePage::class, fn () => new RecipePage(fn () => ['93.184.216.34']));
    $user = User::factory()->create();
    aiFood('Flour');
    $ld = json_encode(['@type' => 'Recipe', 'name' => 'Pancakes', 'recipeIngredient' => ['200 g flour']]);
    Http::fake(['recipes.example/*' => Http::response("<script type=\"application/ld+json\">{$ld}</script>")]);
    $llm = RecordingLlmClient::answering([
        'name' => 'Pancakes', 'servings' => 4, 'instructions' => ['Mix.', 'Fry.'],
        'items' => [['name' => 'Flour', 'search' => 'flour', 'grams' => 200]],
    ])->install();

    $recipe = app(FoodAi::class)->recipeImport($user, 'https://recipes.example/pancakes');

    expect($recipe->name)->toBe('Pancakes')
        ->and($recipe->servings)->toBe(4)
        ->and($recipe->items[0]->food?->name)->toBe('Flour')
        ->and($llm->userContent()[0]['text'])->toContain('recipeIngredient')
        ->and($llm->system())->toStartWith(file_get_contents(resource_path('prompts/recipe-import.md')));
});

it('RCP-003 says the page could not be fetched, and asks nothing, when the page is refused', function () {
    app()->bind(RecipePage::class, fn () => new RecipePage(fn () => ['127.0.0.1']));
    Http::fake();
    $llm = RecordingLlmClient::answering([])->install();

    expect(fn () => app(FoodAi::class)->recipeImport(User::factory()->create(), 'http://localhost/secret'))
        ->toThrow(PageUnavailable::class, 'We couldn\'t fetch that page.');
    expect($llm->calls)->toBe([]);
    Http::assertNothingSent();
});

it('FOOD-042 FOOD-043 FOOD-044 RCP-010 returns drafts and saves nothing', function () {
    $user = User::factory()->create();
    aiFood('Chicken breast');
    (new RecordingLlmClient([
        ['tool_calls' => [['id' => 'a', 'name' => 'answer', 'arguments' => ['items' => [['name' => 'Chicken', 'search' => 'chicken breast', 'grams' => 120]]]]]],
        ['tool_calls' => [['id' => 'b', 'name' => 'answer', 'arguments' => ['items' => [['name' => 'Chicken', 'search' => 'chicken breast', 'grams' => 120]]]]]],
        ['tool_calls' => [['id' => 'c', 'name' => 'answer', 'arguments' => [
            'name' => 'Granola', 'brand' => null, 'serving' => null,
            'per_100g' => ['kcal' => 450, 'protein' => 9.5, 'fat' => 18, 'carbs' => 60, 'fiber' => 7],
        ]]]],
        ['tool_calls' => [['id' => 'd', 'name' => 'answer', 'arguments' => [
            'name' => 'Bowl', 'servings' => 1, 'instructions' => ['Eat.'], 'items' => [['name' => 'Chicken', 'search' => 'chicken breast', 'grams' => 200]],
        ]]]],
    ]))->install();
    $ai = app(FoodAi::class);
    $foods = Food::count();

    $writes = writesDuring(function () use ($ai, $user) {
        $ai->photo($user, PLATE_JPEG);
        $ai->describe($user, 'chicken');
        $ai->label(PLATE_JPEG);
        $ai->recipe($user, recipeBrief(['diets' => []]));
    });

    expect($writes)->toBe([])->and(Food::count())->toBe($foods);
});
