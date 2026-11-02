<?php

use App\Actions\SavedMeals\DeleteSavedMeal;
use App\Models\SavedMeal;
use App\Models\SavedMealFood;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;

// Ported from rp-clone ios/Tests/RPCoreTests/Features/SavedMeals/SavedMealsModelTests.swift (delete).
uses(RefreshDatabase::class);

it('MEAL-071 deleting removes the saved meal and its foods', function () {
    $user = User::factory()->create();
    $meal = SavedMeal::factory()->for($user)->create();
    SavedMealFood::factory()->count(2)->create(['saved_meal_id' => $meal->id]);
    $kept = SavedMeal::factory()->for($user)->create();

    (new DeleteSavedMeal)($user, $meal->id);

    expect(SavedMeal::query()->pluck('id')->all())->toBe([$kept->id])
        ->and(SavedMealFood::query()->count())->toBe(0);
});

it('MEAL-071 never deletes another user\'s saved meal', function () {
    $meal = SavedMeal::factory()->create();

    expect(fn () => (new DeleteSavedMeal)(User::factory()->create(), $meal->id))->toThrow(ModelNotFoundException::class)
        ->and($meal->fresh())->not->toBeNull();
});
