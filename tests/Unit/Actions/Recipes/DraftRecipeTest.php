<?php

use App\Actions\Recipes\DraftRecipe;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\RecordingLlmClient;

// The brief recipe_generate sends. The AI is the recording fake; the profile is a row, so it runs on the database.
uses(RefreshDatabase::class);

beforeEach(fn () => Http::preventStrayRequests());

it('RCP-010 the profile\'s diet filters apply, and the words the user typed are sent trimmed', function () {
    $user = User::factory()->create();
    Profile::factory()->for($user)->create(['diet_filters' => ['vegan', 'gluten_free']]);
    $llm = RecordingLlmClient::answering(['name' => 'Tofu bowl', 'servings' => 2, 'instructions' => [], 'items' => []])->install();

    $recipe = app(DraftRecipe::class)->generate($user, protein: 68, fat: 28, carbs: 128, kcal: 1040, mealType: 'dinner',
        complexity: 'moderate', ingredients: [' tofu ', 'rice'], preferences: '  Not too spicy. ');

    expect($recipe->name)->toBe('Tofu bowl')
        ->and(json_decode($llm->userContent()[0]['text'], true))->toBe([
            'target_macros_per_serving' => ['protein' => 68, 'fat' => 28, 'carbs' => 128, 'kcal' => 1040],
            'meal_type' => 'dinner',
            'ingredients_to_use' => ['tofu', 'rice'],
            'complexity' => 'moderate',
            'diet_filters' => ['gluten_free', 'vegan'],
            'preferences' => 'Not too spicy.',
        ]);
});

it('RCP-010 no calories, no ingredients, blank preferences and no profile send only the macros, meal type and complexity', function () {
    $llm = RecordingLlmClient::answering(['name' => 'Bowl', 'servings' => 1, 'instructions' => [], 'items' => []])->install();

    app(DraftRecipe::class)->generate(User::factory()->create(), protein: 40, fat: 15, carbs: 60, kcal: null, mealType: 'lunch',
        complexity: 'simple', ingredients: [], preferences: "  \n");

    expect(json_decode($llm->userContent()[0]['text'], true))->toBe([
        'target_macros_per_serving' => ['protein' => 40, 'fat' => 15, 'carbs' => 60],
        'meal_type' => 'lunch',
        'complexity' => 'simple',
    ]);
});
