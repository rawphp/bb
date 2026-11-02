<?php

use App\Domain\SavedMeals\SavedMeals;

// Ported from rp-clone ios/Tests/RPCoreTests/Features/SavedMeals/SavedMealsModelTests.swift: the rules its
// SavedMealsModel kept on the phone now hold here.

it('MEAL-071 the list shows each saved meal with its food count', function () {
    expect(SavedMeals::detail(3))->toBe('3 foods')
        ->and(SavedMeals::detail(1))->toBe('1 food')
        ->and(SavedMeals::detail(0))->toBe('0 foods');
});

it('MEAL-071 a name is trimmed', function () {
    expect(SavedMeals::name('  Cut dinner '))->toBe('Cut dinner')
        ->and(SavedMeals::name("\tOats\n"))->toBe('Oats');
});

it('MEAL-071 a blank name is no name', function () {
    expect(SavedMeals::name('   '))->toBeNull()
        ->and(SavedMeals::name(''))->toBeNull();
});
