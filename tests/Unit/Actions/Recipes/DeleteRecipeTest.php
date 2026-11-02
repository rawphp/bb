<?php

use App\Actions\Recipes\DeleteRecipe;
use App\Models\PlannedRecipe;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;

// The action's job is the recipe row it hides, so it runs on the database.
uses(RefreshDatabase::class);

it('RCP-021 deletes the user\'s recipe softly, and the meals it was planned in keep their copy', function () {
    $user = User::factory()->create();
    $recipe = Recipe::factory()->for($user)->create();
    $planned = PlannedRecipe::factory()->create(['recipe_id' => $recipe->id]);
    $copy = $planned->recipe;

    (new DeleteRecipe)($user, $recipe->id);

    expect(Recipe::query()->find($recipe->id))->toBeNull()
        ->and(Recipe::withTrashed()->find($recipe->id)?->trashed())->toBeTrue()
        ->and($planned->fresh())->not->toBeNull()
        ->and($planned->fresh()->recipe)->toBe($copy)
        ->and($planned->fresh()->sourceRecipe?->id)->toBe($recipe->id);
});

it('RCP-020 never deletes a library recipe or another user\'s recipe', function () {
    $user = User::factory()->create();
    $library = Recipe::factory()->create();
    $theirs = Recipe::factory()->for(User::factory()->create())->create();

    expect(fn () => (new DeleteRecipe)($user, $library->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => (new DeleteRecipe)($user, $theirs->id))->toThrow(ModelNotFoundException::class)
        ->and(Recipe::query()->count())->toBe(2);
});
