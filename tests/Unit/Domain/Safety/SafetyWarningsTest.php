<?php

use App\Domain\Model\Meal;
use App\Domain\Model\MealTarget;
use App\Domain\Model\PhaseType;
use App\Domain\Safety\IntakeWeek;
use App\Domain\Safety\SafetyWarning;
use App\Domain\Safety\SafetyWarnings;

function intakeWeek(float $consumed, float $target): IntakeWeek
{
    return new IntakeWeek(averageConsumedKcal: $consumed, averageTargetKcal: $target);
}

function safetyMeal(string $name, float $kcal = 500, float $fat = 15, float $carbs = 50): Meal
{
    return new Meal(id: $name, name: $name, target: new MealTarget(kcal: $kcal, proteinG: 30, fatG: $fat, carbsG: $carbs));
}

it('SAFE-013 warns on a GLP-1 after two weeks running under 80% of target', function () {
    $low = [intakeWeek(2000, 2000), intakeWeek(1590, 2000), intakeWeek(1599, 2000)];

    expect(SafetyWarnings::glp1MuscleLoss(usesGLP1: true, weeks: $low))->toEqual(SafetyWarning::glp1MuscleLoss())
        ->and(SafetyWarning::glp1MuscleLoss()->message())
        ->toBe("You've eaten under 80% of your target for 2 weeks. On a GLP-1 that risks losing muscle. Try to eat to your target.");
});

it('SAFE-013 does not warn without the drug, for one low week, at exactly 80%, or once the latest week recovers', function () {
    $low = [intakeWeek(1500, 2000), intakeWeek(1500, 2000)];

    expect(SafetyWarnings::glp1MuscleLoss(usesGLP1: false, weeks: $low))->toBeNull()
        ->and(SafetyWarnings::glp1MuscleLoss(usesGLP1: true, weeks: [intakeWeek(1500, 2000)]))->toBeNull()
        ->and(SafetyWarnings::glp1MuscleLoss(usesGLP1: true, weeks: [intakeWeek(1500, 2000), intakeWeek(1600, 2000)]))->toBeNull()
        ->and(SafetyWarnings::glp1MuscleLoss(usesGLP1: true, weeks: [intakeWeek(1500, 2000), intakeWeek(1500, 2000), intakeWeek(1900, 2000)]))->toBeNull()
        ->and(SafetyWarnings::glp1MuscleLoss(usesGLP1: true, weeks: []))->toBeNull();
});

it('SAFE-013 keeps the fat-loss rate cap at 1.0% a week', function () {
    expect(SafetyWarnings::MAX_FAT_LOSS_RATE_PERCENT)->toBe(1.0);
});

it('SAFE-015 warns, never blocks, on a step target above 12,000 in a fat-loss phase', function () {
    expect(SafetyWarnings::stepTarget(dailySteps: 12_001, phase: PhaseType::FatLoss))->toEqual(SafetyWarning::highStepTarget())
        ->and(SafetyWarnings::stepTarget(dailySteps: 15_000, phase: PhaseType::MiniCut))->toEqual(SafetyWarning::highStepTarget())
        ->and(SafetyWarnings::stepTarget(dailySteps: 12_000, phase: PhaseType::FatLoss))->toBeNull()
        ->and(SafetyWarnings::stepTarget(dailySteps: 15_000, phase: PhaseType::Maintenance))->toBeNull()
        ->and(SafetyWarnings::stepTarget(dailySteps: 15_000, phase: PhaseType::MuscleGain))->toBeNull()
        ->and(SafetyWarning::highStepTarget()->message())
        ->toBe('More than 12,000 steps a day can hurt your recovery from lifting.');
});

it('SAFE-018 names fat and the fix on a day under 15% fat', function () {
    // 2,000 kcal, 30 g fat = 270 kcal = 13.5%. The floor is 300 kcal = 33.3 g, so 34 g.
    $meals = [safetyMeal('Breakfast', kcal: 1000, fat: 15), safetyMeal('Dinner', kcal: 1000, fat: 15)];

    expect(SafetyWarnings::nutrients($meals))->toEqual([SafetyWarning::dayFatBelowFloor(minimumG: 34)])
        ->and(SafetyWarning::dayFatBelowFloor(minimumG: 34)->message())
        ->toBe("Fat is under 15% of today's calories. Raise fat to at least 34 g.");

    $atFloor = [safetyMeal('Breakfast', kcal: 900, fat: 15)]; // 135 kcal = 15%
    expect(SafetyWarnings::nutrients($atFloor))->toBe([]);
});

it('SAFE-018 names the meal, the macro and the fix on a meal under 5 g fat or carbs', function () {
    $meals = [
        safetyMeal('Breakfast', kcal: 300, fat: 4.9, carbs: 5),
        safetyMeal('Lunch', kcal: 300, fat: 20, carbs: 4),
        safetyMeal('Dinner', kcal: 300, fat: 20, carbs: 30),
    ];

    expect(SafetyWarnings::nutrients($meals))
        ->toEqual([SafetyWarning::mealFatBelowMinimum('Breakfast'), SafetyWarning::mealCarbsBelowMinimum('Lunch')])
        ->and(SafetyWarning::mealFatBelowMinimum('Breakfast')->message())
        ->toBe('Breakfast has under 5 g fat. Add fat to this meal.')
        ->and(SafetyWarning::mealCarbsBelowMinimum('Lunch')->message())
        ->toBe('Lunch has under 5 g carbs. Add carbs to this meal.');
});

it('SAFE-018 has nothing to warn about on an empty day', function () {
    expect(SafetyWarnings::nutrients([]))->toBe([]);
});

it('ONB-021 recommends Maintenance first for Fat loss or Mini-cut after a recent diet', function () {
    expect(SafetyWarnings::maintenanceFirst(goal: PhaseType::FatLoss, finishedFatLossRecently: true))->toEqual(SafetyWarning::maintenanceFirst())
        ->and(SafetyWarnings::maintenanceFirst(goal: PhaseType::MiniCut, finishedFatLossRecently: true))->toEqual(SafetyWarning::maintenanceFirst())
        ->and(SafetyWarning::maintenanceFirst()->recommendedPhase())->toBe(PhaseType::Maintenance)
        ->and(SafetyWarning::highStepTarget()->recommendedPhase())->toBeNull()
        ->and(SafetyWarning::maintenanceFirst()->message())
        ->toBe('You finished a diet in the last 8 weeks. Start with Maintenance so your body and hunger can recover before cutting again. You can still choose Fat loss.');
});

it('ONB-021 recommends nothing without a recent diet, or for another goal', function () {
    expect(SafetyWarnings::maintenanceFirst(goal: PhaseType::FatLoss, finishedFatLossRecently: false))->toBeNull()
        ->and(SafetyWarnings::maintenanceFirst(goal: PhaseType::MuscleGain, finishedFatLossRecently: true))->toBeNull()
        ->and(SafetyWarnings::maintenanceFirst(goal: PhaseType::Maintenance, finishedFatLossRecently: true))->toBeNull();
});
