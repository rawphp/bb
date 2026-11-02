<?php

use App\Domain\Model\PhaseType;
use App\Domain\Phases\PhaseReadiness;
use App\Domain\Phases\ReadinessFailure;

// 70 kg lean mass is 154.3 lb: fat floor 46.3 g, carb floor 231.5 g.
function readiness(
    PhaseType $type = PhaseType::FatLoss, bool $diet = true, bool $training = true, bool $calendar = true,
    float $fat = 60, float $carbs = 300, float $lean = 70,
): PhaseReadiness {
    return PhaseReadiness::check(
        newPhase: $type, dietFatigueLow: $diet, trainingFatigueLow: $training, calendarClear: $calendar,
        dailyFatGrams: $fat, trainingDayCarbGrams: $carbs, leanMassKg: $lean,
    );
}

it('PH-046 ready when everything passes', function () {
    foreach ([PhaseType::FatLoss, PhaseType::MiniCut, PhaseType::MuscleGain] as $type) {
        $result = readiness($type);
        expect($result->failures)->toBe([])
            ->and($result->recommendsMaintenanceFirst())->toBeFalse();
    }
});

it('PH-046 each false answer fails', function () {
    expect(readiness(diet: false)->failures)->toBe([ReadinessFailure::DietFatigueHigh])
        ->and(readiness(training: false)->failures)->toBe([ReadinessFailure::TrainingFatigueHigh])
        ->and(readiness(calendar: false)->failures)->toBe([ReadinessFailure::CalendarNotClear])
        ->and(readiness(diet: false, training: false, calendar: false)->failures)->toBe([
            ReadinessFailure::DietFatigueHigh, ReadinessFailure::TrainingFatigueHigh, ReadinessFailure::CalendarNotClear,
        ])
        ->and(readiness(diet: false)->recommendsMaintenanceFirst())->toBeTrue();
});

it('PH-046 fat and carb floors per lb of lean mass', function () {
    expect(readiness(fat: 46)->failures)->toBe([ReadinessFailure::FatTooLow])
        ->and(readiness(fat: 47)->failures)->toBe([])
        ->and(readiness(carbs: 231)->failures)->toBe([ReadinessFailure::CarbsTooLow])
        ->and(readiness(carbs: 232)->failures)->toBe([])
        ->and(readiness(fat: 30, carbs: 100)->failures)->toBe([ReadinessFailure::FatTooLow, ReadinessFailure::CarbsTooLow])
        ->and(readiness(fat: 30)->recommendsMaintenanceFirst())->toBeTrue()
        ->and(PhaseReadiness::MINIMUM_FAT_GRAMS_PER_LB_LEAN)->toBe(0.3)
        ->and(PhaseReadiness::minimumCarbGramsPerLbLean())->toBe(1.5);
});

it('PH-046 maintenance needs no check', function () {
    $result = readiness(PhaseType::Maintenance, diet: false, training: false, calendar: false, fat: 0, carbs: 0);
    expect($result->failures)->toBe([])
        ->and($result->recommendsMaintenanceFirst())->toBeFalse();
});
