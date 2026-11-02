<?php

use App\Domain\Scan\LabelDraft;

it('FOOD-042 FOOD-060 the serving\'s grams are the serving size and its weight, and nutrition is for that serving', function () {
    $draft = LabelDraft::perServing(servingGrams: 40, protein: 10, fat: 12, carbs: 60, fiber: 8);

    expect($draft->servingGrams)->toBe(40.0)
        ->and([$draft->protein, $draft->fat, $draft->carbs, $draft->fiber])->toBe([4.0, 4.8, 24.0, 3.2])
        // FOOD-060: the calories the saved custom food will show, from these macros: 4 × 4 + 4 × 24 + 9 × 4.8.
        ->and($draft->kcal)->toBe(155.0);
});

it('FOOD-042 a label with no serving is per hundred grams', function () {
    $draft = LabelDraft::perServing(servingGrams: null, protein: 10, fat: 12, carbs: 60, fiber: 8);

    expect($draft->servingGrams)->toBe(100.0)
        ->and([$draft->protein, $draft->fat, $draft->carbs, $draft->fiber])->toBe([10.0, 12.0, 60.0, 8.0])
        ->and($draft->kcal)->toBe(388.0);
});

it('FOOD-042 a serving weighing nothing falls back to hundred grams', function () {
    expect(LabelDraft::perServing(servingGrams: 0, protein: 1, fat: 1, carbs: 1, fiber: 1)->servingGrams)->toBe(100.0);
});

it('FOOD-042 FOOD-060 nutrition is rounded to one decimal, and calories come from the rounded macros to a whole number', function () {
    $draft = LabelDraft::perServing(servingGrams: 33, protein: 7.77, fat: 1.234, carbs: 10.05, fiber: 0.04);

    expect($draft->protein)->toBe(2.6)
        ->and($draft->fat)->toBe(0.4)
        ->and($draft->carbs)->toBe(3.3)
        ->and($draft->fiber)->toBe(0.0)
        // 4 × 2.6 + 4 × 3.3 + 9 × 0.4 = 27.2
        ->and($draft->kcal)->toBe(27.0);
});
