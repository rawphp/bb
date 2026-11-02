<?php

use App\Domain\Model\ClosedRange;
use App\Domain\Phases\CalorieStepUp;
use App\Domain\Phases\CalorieStepUpMode;
use App\Domain\Phases\MaintenanceHold;
use App\Domain\Phases\StartingCalories;

it('PH-035 measured expenditure with formula fallback', function () {
    $pick = fn (?float $measured, int $weeks = 8, bool $trainingOnly = false) => StartingCalories::resolve(
        measuredKcal: $measured, previousPhaseWeeks: $weeks, isTrainingOnly: $trainingOnly, formulaKcal: 2500,
    );
    expect($pick(2800))->toEqual(StartingCalories::measured(2800))
        ->and($pick(2800, weeks: 3))->toEqual(StartingCalories::measured(2800))
        ->and($pick(null))->toEqual(StartingCalories::formula(2500))
        ->and($pick(2800, weeks: 2))->toEqual(StartingCalories::formula(2500))
        ->and($pick(2800, trainingOnly: true))->toEqual(StartingCalories::formula(2500))
        ->and($pick(2800)->kcal)->toEqual(2800)
        ->and($pick(null)->kcal)->toEqual(2500);
});

it('PH-037 maintenance corridor is centred on the start trend weight', function () {
    $corridor = MaintenanceHold::corridor(startTrendWeight: 80);
    expect($corridor->lower)->toEqualWithDelta(79.2, 1e-9)
        ->and($corridor->upper)->toEqualWithDelta(80.8, 1e-9)
        ->and($corridor->contains(80.5))->toBeTrue()
        // The pre-diet weight is outside the corridor.
        ->and($corridor->contains(88))->toBeFalse();
});

it('PH-044 calorie step up', function () {
    $target = fn (int $week, float $step = 300, CalorieStepUpMode $mode = CalorieStepUpMode::StepUp) => CalorieStepUp::targetKcal(
        week: $week, lastFatLossKcal: 2000, maintenanceKcal: 2800, stepKcal: $step, mode: $mode,
    );
    expect($target(0))->toEqual(2300)
        ->and($target(2))->toEqual(2300)
        ->and($target(3))->toEqual(2600)
        ->and($target(6))->toEqual(2800)
        ->and($target(12))->toEqual(2800);
});

it('PH-044 step is clamped and straight to maintenance is an option', function () {
    expect(CalorieStepUp::stepRange())->toEqual(new ClosedRange(250.0, 500.0))
        ->and(CalorieStepUp::clampedStep(100))->toEqual(250)
        ->and(CalorieStepUp::clampedStep(400))->toEqual(400)
        ->and(CalorieStepUp::clampedStep(900))->toEqual(500)
        // A 100 kcal step is used as 250.
        ->and(CalorieStepUp::targetKcal(
            week: 0, lastFatLossKcal: 2000, maintenanceKcal: 2800, stepKcal: 100, mode: CalorieStepUpMode::StepUp,
        ))->toEqual(2250)
        ->and(CalorieStepUp::targetKcal(
            week: 0, lastFatLossKcal: 2000, maintenanceKcal: 2800, stepKcal: 300, mode: CalorieStepUpMode::StraightToMaintenance,
        ))->toEqual(2800)
        ->and(CalorieStepUp::WEEKS_PER_STEP)->toBe(3)
        ->and(CalorieStepUp::EXPLANATION)->toBe('A 12-week diet is a 16-week process');
});
