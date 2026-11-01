<?php

use App\Domain\Model\PhaseType;
use App\Domain\Model\PlanWeek;
use App\Domain\Plan\DeloadCalories;
use App\Domain\Plan\UnplannedBreak;
use Tests\Unit\Domain\Plan\PlanFixtures as F;

it('PLAN-022 every deload runs at maintenance by default', function () {
    foreach (PhaseType::cases() as $type) {
        expect(DeloadCalories::runsAtMaintenance($type, dietBreakTurnedOff: false))->toBeTrue($type->value)
            ->and(DeloadCalories::label($type, dietBreakTurnedOff: false))->toBe('Deload · maintenance calories', $type->value);
    }
    expect(DeloadCalories::MAINTENANCE_LABEL)->toBe('Deload · maintenance calories');
});

it('PLAN-022 a fat-loss deload at maintenance is a diet break', function () {
    expect(DeloadCalories::isDietBreak(PhaseType::FatLoss, isDeload: true, dietBreakTurnedOff: false))->toBeTrue()
        ->and(DeloadCalories::isDietBreak(PhaseType::FatLoss, isDeload: false, dietBreakTurnedOff: false))->toBeFalse();
});

it('PLAN-009 a Mini-cut deload is a diet break too', function () {
    expect(DeloadCalories::isDietBreak(PhaseType::MiniCut, isDeload: true, dietBreakTurnedOff: false))->toBeTrue()
        ->and(DeloadCalories::canTurnOffDietBreak(PhaseType::MiniCut))->toBeTrue()
        ->and(DeloadCalories::runsAtMaintenance(PhaseType::MiniCut, dietBreakTurnedOff: true))->toBeFalse()
        ->and(DeloadCalories::isDietBreak(PhaseType::MiniCut, isDeload: true, dietBreakTurnedOff: true))->toBeFalse();
});

it('PLAN-023 only a Fat loss or Mini-cut diet break can be turned off', function () {
    expect(DeloadCalories::canTurnOffDietBreak(PhaseType::FatLoss))->toBeTrue()
        ->and(DeloadCalories::canTurnOffDietBreak(PhaseType::MiniCut))->toBeTrue()
        ->and(DeloadCalories::canTurnOffDietBreak(PhaseType::MuscleGain))->toBeFalse()
        ->and(DeloadCalories::canTurnOffDietBreak(PhaseType::Maintenance))->toBeFalse();
});

it('PLAN-023 turning the diet break off leaves the deload in a deficit with a warning', function () {
    expect(DeloadCalories::runsAtMaintenance(PhaseType::FatLoss, dietBreakTurnedOff: true))->toBeFalse()
        ->and(DeloadCalories::label(PhaseType::FatLoss, dietBreakTurnedOff: true))->toBeNull()
        ->and(DeloadCalories::isDietBreak(PhaseType::FatLoss, isDeload: true, dietBreakTurnedOff: true))->toBeFalse()
        ->and(DeloadCalories::DEFICIT_DELOAD_WARNING)->toBe('Deloading in a deficit is one of the biggest mistakes RP warns about.');
});

it('PLAN-023 the turn-off flag is ignored outside fat loss', function () {
    foreach ([PhaseType::MuscleGain, PhaseType::Maintenance] as $type) {
        expect(DeloadCalories::runsAtMaintenance($type, dietBreakTurnedOff: true))->toBeTrue($type->value)
            ->and(DeloadCalories::label($type, dietBreakTurnedOff: true))->toBe(DeloadCalories::MAINTENANCE_LABEL);
    }
});

it('PLAN-024 a muscle-gain deload is a planned week, not a diet break', function () {
    expect(DeloadCalories::runsAtMaintenance(PhaseType::MuscleGain, dietBreakTurnedOff: false))->toBeTrue()
        ->and(DeloadCalories::isDietBreak(PhaseType::MuscleGain, isDeload: true, dietBreakTurnedOff: false))->toBeFalse()
        ->and(DeloadCalories::isDietBreak(PhaseType::Maintenance, isDeload: true, dietBreakTurnedOff: false))->toBeFalse();
});

it('PLAN-024 a muscle-gain deload counts and does not move the end', function () {
    $weeks = array_map(fn (int $week) => new PlanWeek(
        start: F::grid()->start($week),
        isDeload: $week === 4,
        isDietBreak: DeloadCalories::isDietBreak(PhaseType::MuscleGain, isDeload: $week === 4, dietBreakTurnedOff: false),
    ), range(0, 4));
    expect(DeloadCalories::countedWeeks($weeks))->toBe(5);

    $phase = F::phase(PhaseType::MuscleGain, 0, 5, 0.375);
    expect(DeloadCalories::extendingEnd($phase, [], keepingEndDate: false, grid: F::grid()))->toBe($phase);
});

it('PLAN-025 diet-break weeks do not count towards the rate or the cap', function () {
    $grid = F::grid();
    $weeks = array_map(fn (int $week) => new PlanWeek(
        start: $grid->start($week),
        isDeload: $week === 5 || $week === 11,
        isDietBreak: $week === 5 || $week === 11,
    ), range(0, 17));

    expect(DeloadCalories::countedWeeks($weeks))->toBe(16)
        ->and(DeloadCalories::countedWeeks([]))->toBe(0);
});

it('PLAN-025 a deload left in a deficit still counts', function () {
    $grid = F::grid();
    $weeks = array_map(fn (int $week) => new PlanWeek(
        start: $grid->start($week), isDeload: $week === 5, isDietBreak: false,
    ), range(0, 5));

    expect(DeloadCalories::countedWeeks($weeks))->toBe(6);
});

it('PLAN-025 each unplanned break moves the end out one week', function () {
    $grid = F::grid();
    $phase = F::phase(PhaseType::FatLoss, 0, 10, -0.75);
    foreach (UnplannedBreak::cases() as $unplanned) {
        $result = DeloadCalories::extendingEnd($phase, [$unplanned], keepingEndDate: false, grid: $grid);
        expect($result->end)->toEqual($grid->start(11), $unplanned->value)
            ->and($result->start)->toEqual($phase->start)
            ->and($result->id)->toBe($phase->id);
    }
    expect(UnplannedBreak::cases())->toHaveCount(3);
});

it('PLAN-025 several breaks move the end out one week each', function () {
    $grid = F::grid();
    $phase = F::phase(PhaseType::FatLoss, 2, 10, -0.75);

    $result = DeloadCalories::extendingEnd(
        $phase, [UnplannedBreak::DropFatigue, UnplannedBreak::RecoveryDietBreak, UnplannedBreak::Away],
        keepingEndDate: false, grid: $grid,
    );

    expect($result->end)->toEqual($grid->start(13));
});

it('PLAN-025 keeping the end date ignores the breaks', function () {
    $phase = F::phase(PhaseType::FatLoss, 0, 10, -0.75);

    expect(DeloadCalories::extendingEnd(
        $phase, [UnplannedBreak::DropFatigue, UnplannedBreak::Away], keepingEndDate: true, grid: F::grid(),
    ))->toBe($phase);
});

it('PLAN-025 an open-ended phase is unchanged', function () {
    $phase = F::phase(PhaseType::Maintenance, 0, null);

    expect(DeloadCalories::extendingEnd($phase, [UnplannedBreak::DropFatigue], keepingEndDate: false, grid: F::grid()))
        ->toBe($phase);
});

it('PLAN-025 no breaks leaves the end where it was', function () {
    $phase = F::phase(PhaseType::FatLoss, 0, 10, -0.75);

    expect(DeloadCalories::extendingEnd($phase, [], keepingEndDate: false, grid: F::grid()))->toBe($phase);
});
