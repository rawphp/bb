<?php

use App\Actions\Foods\DeleteCustomFood;
use App\Models\Food;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;

// The action's job is the foods row it hides, so it runs on the database.
uses(RefreshDatabase::class);

it('FOOD-061 deletes the user\'s custom food softly, so meals that used it still read it', function () {
    $user = User::factory()->create();
    $food = Food::factory()->custom($user)->create();

    (new DeleteCustomFood)($user, $food->id);

    expect(Food::query()->find($food->id))->toBeNull()
        ->and(Food::withTrashed()->find($food->id)?->trashed())->toBeTrue();
});

it('FOOD-061 never deletes another user\'s custom food or a library food', function () {
    $user = User::factory()->create();
    $theirs = Food::factory()->custom(User::factory()->create())->create();
    $library = Food::factory()->create();

    expect(fn () => (new DeleteCustomFood)($user, $theirs->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => (new DeleteCustomFood)($user, $library->id))->toThrow(ModelNotFoundException::class)
        ->and(Food::query()->count())->toBe(2);
});
