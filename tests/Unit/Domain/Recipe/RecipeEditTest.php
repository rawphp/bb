<?php

use App\Domain\Recipe\RecipeEdit;

// Ported from rp-clone ios/Tests/RPCoreTests/Features/Recipes (RecipeEditorModelTests, RecipeDraftModelTests): what a
// saved recipe keeps of what the editor or a draft sends.

it('RCP-001 blank steps are not saved, and the others are trimmed', function () {
    expect(RecipeEdit::steps(['  Cook the rice. ', '', "  \n", 'Grill it.']))->toBe(['Cook the rice.', 'Grill it.'])
        ->and(RecipeEdit::steps([]))->toBe([]);
});

it('RCP-001 FOOD-006 raw or cooked can be chosen only for a food with a cooked value', function () {
    expect(RecipeEdit::hasCookedValue(0.75))->toBeTrue()
        ->and(RecipeEdit::hasCookedValue(null))->toBeFalse()
        ->and(RecipeEdit::hasCookedValue(0.0))->toBeFalse()
        ->and(RecipeEdit::isCooked(true, 0.75))->toBeTrue()
        ->and(RecipeEdit::isCooked(false, 0.75))->toBeFalse()
        ->and(RecipeEdit::isCooked(true, null))->toBeFalse();
});

it('RCP-003 RCP-010 a draft starts at between 1 and 100 servings, so it can be saved as it is', function (float $servings, float $kept) {
    expect(RecipeEdit::draftServings($servings))->toBe($kept);
})->with([
    [4, 4.0],
    [1, 1.0],
    [0, 1.0],
    [250, 100.0],
]);
