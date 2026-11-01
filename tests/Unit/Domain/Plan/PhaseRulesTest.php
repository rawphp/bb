<?php

use App\Domain\Model\ClosedRange;
use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\Phase;
use App\Domain\Model\PhaseType;
use App\Domain\Plan\PhaseRefusal;
use App\Domain\Plan\PhaseRules;
use Tests\Unit\Domain\Plan\PlanFixtures as F;

function phaseRefusal(PhaseType $type, ?int $weeks, ?float $rate = null, int $dietBreaks = 0): ?PhaseRefusal
{
    return PhaseRules::validate($type, $weeks, $rate, $dietBreaks);
}

it('PH-005 only Maintenance may have no end', function () {
    expect(phaseRefusal(PhaseType::Maintenance, null))->toBeNull()
        ->and(phaseRefusal(PhaseType::FatLoss, null))->toEqual(PhaseRefusal::endDateRequired())
        ->and(phaseRefusal(PhaseType::MiniCut, null))->toEqual(PhaseRefusal::endDateRequired())
        ->and(phaseRefusal(PhaseType::MuscleGain, null))->toEqual(PhaseRefusal::endDateRequired());
});

it('TBL-010 minimum lengths', function () {
    expect(phaseRefusal(PhaseType::FatLoss, 1))->toEqual(PhaseRefusal::tooShort(2))
        ->and(phaseRefusal(PhaseType::FatLoss, 2))->toBeNull()
        ->and(phaseRefusal(PhaseType::MuscleGain, 3))->toEqual(PhaseRefusal::tooShort(4))
        ->and(phaseRefusal(PhaseType::MuscleGain, 4))->toBeNull()
        ->and(phaseRefusal(PhaseType::Maintenance, 1))->toEqual(PhaseRefusal::tooShort(2))
        ->and(phaseRefusal(PhaseType::Maintenance, 2))->toBeNull()
        ->and(phaseRefusal(PhaseType::MiniCut, 1))->toEqual(PhaseRefusal::tooShort(2));
});

it('TBL-010 maximum lengths', function () {
    expect(phaseRefusal(PhaseType::FatLoss, 16))->toBeNull()
        ->and(phaseRefusal(PhaseType::FatLoss, 17))->toEqual(PhaseRefusal::tooLong(16))
        ->and(phaseRefusal(PhaseType::MuscleGain, 16))->toBeNull()
        ->and(phaseRefusal(PhaseType::MuscleGain, 17))->toEqual(PhaseRefusal::tooLong(16))
        ->and(phaseRefusal(PhaseType::MiniCut, 6))->toBeNull()
        ->and(phaseRefusal(PhaseType::MiniCut, 7))->toEqual(PhaseRefusal::tooLong(6))
        ->and(phaseRefusal(PhaseType::Maintenance, 200))->toBeNull();
});

it('SAFE-006 fat loss over sixteen weeks is refused', function () {
    expect(phaseRefusal(PhaseType::FatLoss, 17))->toEqual(PhaseRefusal::tooLong(16))
        ->and(phaseRefusal(PhaseType::FatLoss, 18, dietBreaks: 1))->toEqual(PhaseRefusal::tooLong(16));
});

it('SAFE-006 sixteen deficit weeks plus diet breaks is allowed', function () {
    expect(phaseRefusal(PhaseType::FatLoss, 18, dietBreaks: 2))->toBeNull()
        ->and(phaseRefusal(PhaseType::FatLoss, 19, dietBreaks: 2))->toEqual(PhaseRefusal::tooLong(16));
});

it('SAFE-007 muscle gain over sixteen weeks is refused even with deloads', function () {
    expect(phaseRefusal(PhaseType::MuscleGain, 17, dietBreaks: 3))->toEqual(PhaseRefusal::tooLong(16))
        ->and(phaseRefusal(PhaseType::MuscleGain, 17))->toEqual(PhaseRefusal::tooLong(16))
        ->and(phaseRefusal(PhaseType::MuscleGain, 16, dietBreaks: 3))->toBeNull();
});

it('PLAN-009 a Mini-cut counts diet breaks like fat loss', function () {
    expect(phaseRefusal(PhaseType::MiniCut, 7))->toEqual(PhaseRefusal::tooLong(6))
        ->and(phaseRefusal(PhaseType::MiniCut, 8, dietBreaks: 2))->toBeNull()
        ->and(phaseRefusal(PhaseType::MiniCut, 9, dietBreaks: 2))->toEqual(PhaseRefusal::tooLong(6));
});

it('TBL-010 rates outside the range are refused', function () {
    $fatLoss = PhaseRefusal::rateOutOfRange(new ClosedRange(-1.0, -0.25));

    expect(phaseRefusal(PhaseType::FatLoss, 8, -0.75))->toBeNull()
        ->and(phaseRefusal(PhaseType::FatLoss, 8, -0.25))->toBeNull()
        ->and(phaseRefusal(PhaseType::FatLoss, 8, -1.0))->toBeNull()
        ->and(phaseRefusal(PhaseType::FatLoss, 8, -1.1))->toEqual($fatLoss)
        ->and(phaseRefusal(PhaseType::FatLoss, 8, -0.2))->toEqual($fatLoss)
        ->and(phaseRefusal(PhaseType::FatLoss, 8, 0.5))->toEqual($fatLoss)
        ->and(phaseRefusal(PhaseType::MuscleGain, 8, 0.125))->toBeNull()
        ->and(phaseRefusal(PhaseType::MuscleGain, 8, 0.5))->toBeNull()
        ->and(phaseRefusal(PhaseType::MuscleGain, 8, 0.6))->toEqual(PhaseRefusal::rateOutOfRange(new ClosedRange(0.125, 0.5)))
        ->and(phaseRefusal(PhaseType::MiniCut, 4, -1.0))->toBeNull()
        ->and(phaseRefusal(PhaseType::MiniCut, 4, -0.5))->toEqual(PhaseRefusal::rateOutOfRange(new ClosedRange(-1.0, -0.75)));
});

it('PH-004 maintenance rate stays within a quarter percent', function () {
    $maintenance = PhaseRefusal::rateOutOfRange(new ClosedRange(-0.25, 0.25));

    expect(phaseRefusal(PhaseType::Maintenance, 4, 0))->toBeNull()
        ->and(phaseRefusal(PhaseType::Maintenance, 4, 0.25))->toBeNull()
        ->and(phaseRefusal(PhaseType::Maintenance, 4, -0.25))->toBeNull()
        ->and(phaseRefusal(PhaseType::Maintenance, 4, 0.3))->toEqual($maintenance)
        ->and(phaseRefusal(PhaseType::Maintenance, 4, -0.3))->toEqual($maintenance);
});

it('PLAN-007 a training-only phase has no rate to check', function () {
    expect(phaseRefusal(PhaseType::MuscleGain, 5, null))->toBeNull()
        ->and(phaseRefusal(PhaseType::FatLoss, 5, null))->toBeNull();
});

it('PH-004 default rates per phase type', function () {
    expect(PhaseRules::defaultRatePercentPerWeek(PhaseType::FatLoss, ExperienceLevel::Intermediate))->toBe(-0.75)
        ->and(PhaseRules::defaultRatePercentPerWeek(PhaseType::MiniCut, ExperienceLevel::Intermediate))->toBe(-1.0)
        ->and(PhaseRules::defaultRatePercentPerWeek(PhaseType::Maintenance, ExperienceLevel::Intermediate))->toBe(0.0)
        ->and(PhaseRules::defaultRatePercentPerWeek(PhaseType::MuscleGain, ExperienceLevel::Beginner))->toBe(0.5)
        ->and(PhaseRules::defaultRatePercentPerWeek(PhaseType::MuscleGain, ExperienceLevel::Intermediate))->toBe(0.375)
        ->and(PhaseRules::defaultRatePercentPerWeek(PhaseType::MuscleGain, ExperienceLevel::Advanced))->toBe(0.25);
});

it('PH-006 adjusting length moves the end to a week boundary', function () {
    $grid = F::grid();
    $phase = F::phase(PhaseType::MuscleGain, 2, 10, 0.375);

    $longer = PhaseRules::adjustingLength($phase, 12, $grid);
    expect($longer->end)->toEqual($grid->start(14))
        ->and($longer->start)->toEqual($phase->start)
        ->and($longer->id)->toBe($phase->id)
        ->and($longer->targetRatePercentPerWeek)->toBe(0.375);

    $shorter = PhaseRules::adjustingLength($phase, 4, $grid);
    expect($shorter->end)->toEqual($grid->start(6));
});

it('PH-006 adjusting length refuses lengths the table forbids', function () {
    $grid = F::grid();
    $phase = F::phase(PhaseType::MuscleGain, 0, 8, 0.375);

    expect(F::refusal(fn () => PhaseRules::adjustingLength($phase, 17, $grid)))->toEqual(PhaseRefusal::tooLong(16))
        ->and(F::refusal(fn () => PhaseRules::adjustingLength($phase, 2, $grid)))->toEqual(PhaseRefusal::tooShort(4))
        ->and(F::refusal(fn () => PhaseRules::adjustingLength($phase, null, $grid)))->toEqual(PhaseRefusal::endDateRequired());
});

it('PH-006 adjusting length counts diet breaks for fat loss', function () {
    $grid = F::grid();
    $phase = F::phase(PhaseType::FatLoss, 0, 8, -0.75);

    expect(PhaseRules::adjustingLength($phase, 18, $grid, dietBreakWeeks: 2)->end)->toEqual($grid->start(18))
        ->and(F::refusal(fn () => PhaseRules::adjustingLength($phase, 18, $grid)))->toEqual(PhaseRefusal::tooLong(16));
});

it('PH-005 maintenance can be made open-ended and back', function () {
    $grid = F::grid();
    $phase = F::phase(PhaseType::Maintenance, 4, 8);

    $open = PhaseRules::adjustingLength($phase, null, $grid);
    expect($open->end)->toBeNull();

    $closed = PhaseRules::adjustingLength($open, 6, $grid);
    expect($closed->end)->toEqual($grid->start(10));
});

it('PH-006 adjusting length refuses a phase not starting on a week boundary', function () {
    $phase = new Phase(
        id: 'phase', type: PhaseType::Maintenance, start: F::date(2026, 1, 7), end: F::date(2026, 2, 2),
        targetRatePercentPerWeek: 0,
    );

    expect(F::refusal(fn () => PhaseRules::adjustingLength($phase, 4, F::grid())))
        ->toEqual(PhaseRefusal::notOnWeekBoundary());
});

it('PH-003 a phase keeps its type, start and rate when its length changes', function () {
    $grid = F::grid();
    $phase = F::phase(PhaseType::FatLoss, 3, 9, -0.5);

    $adjusted = PhaseRules::adjustingLength($phase, 10, $grid);

    expect($adjusted->type)->toBe(PhaseType::FatLoss)
        ->and($adjusted->start)->toEqual($grid->start(3))
        ->and($adjusted->end)->toEqual($grid->start(13))
        ->and($adjusted->targetRatePercentPerWeek)->toBe(-0.5);
});
