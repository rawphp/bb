<?php

use App\Ai\AiRecipe;
use App\Ai\MatchedItem;
use App\Capabilities\Nutrition\Data\RecipeGenerateOutput;
use App\Capabilities\Nutrition\Data\RecipeImportOutput;
use App\Capabilities\Nutrition\RecipeDrafts;
use App\Models\Food;

// Ported from rp-clone ios/Tests/RPCoreTests/Features/Recipes (RecipeDraftModelTests, RecipeGenerateModelTests,
// RecipeImportModelTests): the confirm step a drafted recipe goes through, as the server now writes it.

function draftFood(string $id, string $name, ?string $brand = null): Food
{
    $food = new Food(['name' => $name, 'brand' => $brand]);
    $food->id = $id;

    return $food;
}

function lemonChicken(int $servings = 4): AiRecipe
{
    return new AiRecipe('Lemon chicken', $servings, ['Marinate.', '  ', ' Roast. '], [
        new MatchedItem('Chicken thighs', 600.0, draftFood('01JBX5F0000000000000000002', 'Chicken breast')),
        new MatchedItem('Sumac', 5.0, null),
        new MatchedItem('Rice', 300.0, draftFood('01JBX5F0000000000000000003', 'Basmati rice', 'Tilda')),
    ]);
}

it('RCP-003 RCP-010 each item shows the food it matched, or No match', function () {
    $draft = RecipeDrafts::of(RecipeImportOutput::class, lemonChicken(), null);

    expect(array_map(fn ($item) => $item->toArray(), $draft->items))->toBe([
        ['name' => 'Chicken thighs', 'grams' => 600.0, 'food_id' => '01JBX5F0000000000000000002', 'match_text' => 'Chicken breast', 'detail' => null],
        ['name' => 'Sumac', 'grams' => 5.0, 'food_id' => null, 'match_text' => 'No match', 'detail' => null],
        ['name' => 'Rice', 'grams' => 300.0, 'food_id' => '01JBX5F0000000000000000003', 'match_text' => 'Basmati rice', 'detail' => 'Tilda'],
    ])
        ->and($draft->name)->toBe('Lemon chicken')
        ->and($draft->servings)->toBe(4.0)
        ->and($draft->steps)->toBe(['Marinate.', 'Roast.']);
});

it('RCP-003 an imported recipe has no meal type: the user picks one', function () {
    expect(RecipeDrafts::of(RecipeImportOutput::class, lemonChicken(), null)->meal_type)->toBeNull();
});

it('RCP-010 the meal type asked for carries on to the editor', function () {
    $draft = RecipeDrafts::of(RecipeGenerateOutput::class, lemonChicken(), 'dinner');

    expect($draft)->toBeInstanceOf(RecipeGenerateOutput::class)
        ->and($draft->meal_type)->toBe('dinner');
});

it('RCP-003 RCP-010 a draft starts at no more servings than a recipe can have', function () {
    expect(RecipeDrafts::of(RecipeImportOutput::class, lemonChicken(250), null)->servings)->toBe(100.0);
});
