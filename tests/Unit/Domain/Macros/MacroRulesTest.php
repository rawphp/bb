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
