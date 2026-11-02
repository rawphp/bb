<?php

use App\Domain\Scan\LabelDraft;

it('FOOD-042 the serving\'s grams are the serving size and its weight, and nutrition is for that serving', function () {
    $draft = LabelDraft::perServing(servingGrams: 40, kcal: 400, protein: 10, fat: 12, carbs: 60, fiber: 8);

    expect($draft->servingGrams)->toBe(40.0)
        ->and([$draft->protein, $draft->fat, $draft->carbs, $draft->fiber])->toBe([4.0, 4.8, 24.0, 3.2])
        ->and($draft->kcal)->toBe(160.0);
});

it('FOOD-042 a label with no serving is per hundred grams', function () {
    $draft = LabelDraft::perServing(servingGrams: null, kcal: 400, protein: 10, fat: 12, carbs: 60, fiber: 8);

    expect($draft->servingGrams)->toBe(100.0)
        ->and([$draft->protein, $draft->fat, $draft->carbs, $draft->fiber])->toBe([10.0, 12.0, 60.0, 8.0])
        ->and($draft->kcal)->toBe(400.0);
});

it('FOOD-042 a serving weighing nothing falls back to hundred grams', function () {
    expect(LabelDraft::perServing(servingGrams: 0, kcal: 1, protein: 1, fat: 1, carbs: 1, fiber: 1)->servingGrams)->toBe(100.0);
});

it('FOOD-042 nutrition is rounded to one decimal and calories to a whole number', function () {
    $draft = LabelDraft::perServing(servingGrams: 33, kcal: 120, protein: 7.77, fat: 1.234, carbs: 10.05, fiber: 0.04);

    expect($draft->protein)->toBe(2.6)
        ->and($draft->fat)->toBe(0.4)
        ->and($draft->carbs)->toBe(3.3)
        ->and($draft->fiber)->toBe(0.0)
        ->and($draft->kcal)->toBe(40.0);
});
