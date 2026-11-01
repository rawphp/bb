<?php

use App\Domain\Macros\DayTarget;
use App\Domain\Macros\MacroRules;
use App\Domain\Model\DayType;

it('NUT-018 calories are four per gram of protein and carbs and nine per gram of fat', function () {
    expect(MacroRules::kcal(proteinG: 10, carbsG: 10, fatG: 10))->toEqual(170)
        ->and(MacroRules::kcal(proteinG: 0, carbsG: 0, fatG: 0))->toEqual(0)
        ->and(MacroRules::kcal(proteinG: 12.5, carbsG: 0.5, fatG: 1))->toEqual(61);
});

it('NUT-018 a day target asks the rule', function () {
    $day = new DayTarget(DayType::Moderate, isCompleted: false, proteinG: 150, fatG: 60, carbsG: 250, carbBoundsWarning: null);

    expect($day->kcal())->toEqual(MacroRules::kcal(proteinG: 150, carbsG: 250, fatG: 60))
        ->and($day->kcal())->toEqual(2140);
});

it('SAFE-009 fat share floor is 15% of the calories in fat grams', function () {
    expect(MacroRules::fatShareFloorG(3000))->toEqualWithDelta(50, 0.001); // 450 kcal / 9
});

it('NUT-012 fat floor is the largest of the share, lean-mass and meal-minimum arms', function () {
    expect(MacroRules::fatFloorG(maxDayKcal: 3000, leanMassKg: 60, mealsPerDay: 4))->toEqualWithDelta(50, 0.001) // 15% of 3000
        ->and(MacroRules::fatFloorG(maxDayKcal: 1800, leanMassKg: 79.2, mealsPerDay: 4))->toEqualWithDelta(52.272, 0.001) // 0.66 x 79.2
        ->and(MacroRules::fatFloorG(maxDayKcal: 300, leanMassKg: 10, mealsPerDay: 6))->toEqualWithDelta(30, 0.001); // 5 g x 6 meals
});
