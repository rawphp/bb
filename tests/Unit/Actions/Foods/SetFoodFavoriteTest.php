<?php

use App\Actions\Foods\SetFoodFavorite;
use App\Models\Food;
use App\Models\FoodFavorite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

// The action's job is the food_favorites row it writes or removes, so it runs on the database.
uses(RefreshDatabase::class);

/** @return list<array{int, string}> */
function favoriteRows(): array
{
    return FoodFavorite::query()->orderBy('id')->get()->map(fn (FoodFavorite $f) => [$f->user_id, $f->food_id])->all();
}

it('FOOD-022 favouriting a food stars it once, however often it is asked', function () {
    $user = User::factory()->create();
    $food = Food::factory()->create();

    (new SetFoodFavorite)($user, $food->id, true);
    (new SetFoodFavorite)($user, $food->id, true);

    expect(favoriteRows())->toBe([[$user->id, $food->id]]);
});

it('FOOD-022 taking a favourite back removes only the user\'s star', function () {
    [$user, $other] = User::factory()->count(2)->create();
    $food = Food::factory()->create();
    FoodFavorite::factory()->create(['user_id' => $user->id, 'food_id' => $food->id]);
    FoodFavorite::factory()->create(['user_id' => $other->id, 'food_id' => $food->id]);

    (new SetFoodFavorite)($user, $food->id, false);
    (new SetFoodFavorite)($user, $food->id, false);

    expect(favoriteRows())->toBe([[$other->id, $food->id]]);
});
