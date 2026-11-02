<?php

use App\Actions\SavedMeals\RenameSavedMeal;
use App\Models\SavedMeal;
use App\Models\SavedMealFood;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;

// Ported from rp-clone ios/Tests/RPCoreTests/Features/SavedMeals/SavedMealsModelTests.swift (rename).
uses(RefreshDatabase::class);

it('MEAL-071 renaming trims the name and keeps the foods', function () {
    $user = User::factory()->create();
    $meal = SavedMeal::factory()->for($user)->create(['name' => 'Bulk dinner']);
    SavedMealFood::factory()->count(3)->create(['saved_meal_id' => $meal->id]);

    $renamed = (new RenameSavedMeal)($user, $meal->id, '  Cut dinner ');

    expect($renamed->name)->toBe('Cut dinner')
        ->and($meal->fresh()->name)->toBe('Cut dinner')
        ->and($meal->foods()->count())->toBe(3);
});

it('MEAL-071 a blank name keeps the old name', function () {
    $user = User::factory()->create();
    $meal = SavedMeal::factory()->for($user)->create(['name' => 'Bulk dinner']);

    expect(fn () => (new RenameSavedMeal)($user, $meal->id, '   '))
        ->toThrow(DomainException::class, 'Give the saved meal a name.')
        ->and($meal->fresh()->name)->toBe('Bulk dinner');
});

it('MEAL-071 the same name changes nothing', function () {
    $user = User::factory()->create();
    $meal = SavedMeal::factory()->for($user)->create(['name' => 'Bulk dinner', 'updated_at' => '2026-10-01 08:00:00']);

    (new RenameSavedMeal)($user, $meal->id, 'Bulk dinner ');

    expect($meal->fresh()->updated_at->toDateTimeString())->toBe('2026-10-01 08:00:00');
});

it('MEAL-071 never renames another user\'s saved meal', function () {
    $meal = SavedMeal::factory()->create(['name' => 'Theirs']);

    expect(fn () => (new RenameSavedMeal)(User::factory()->create(), $meal->id, 'Mine now'))->toThrow(ModelNotFoundException::class)
        ->and($meal->fresh()->name)->toBe('Theirs');
});
