<?php

use App\Domain\Model\BodyFatBucket;
use App\Domain\Model\Mesocycle;
use App\Domain\Model\Phase;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Priority;
use App\Domain\Model\Sex;
use App\Domain\Phases\MaintenanceSuggestion;
use App\Domain\Phases\PhaseWarning;
use App\Domain\Phases\PhaseWarningKind;
use App\Domain\Phases\PhaseWarnings;

function utcDate(int $y, int $m, int $d): DateTimeImmutable
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->setDate($y, $m, $d)->setTime(0, 0);
}

/**
 * @param  array<string, Priority>  $priorities
 */
function warningsMeso(PhaseType $phase, bool $resensitization = false, array $priorities = ['chest' => Priority::Grow]): Mesocycle
{
    return new Mesocycle(
        id: 'meso', phase: $phase, weeks: 5, sessions: [], musclePriorities: $priorities, isResensitization: $resensitization,
    );
}

it('PH-010 more than two emphasized in muscle gain warns', function () {
    $three = ['chest' => Priority::Emphasize, 'back' => Priority::Emphasize, 'quads' => Priority::Emphasize];
    $two = ['chest' => Priority::Emphasize, 'back' => Priority::Emphasize, 'quads' => Priority::Grow];
    expect(PhaseWarnings::tooManyEmphasized(PhaseType::MuscleGain, $three))->toEqual(PhaseWarning::tooManyEmphasized())
        ->and(PhaseWarnings::tooManyEmphasized(PhaseType::MuscleGain, $two))->toBeNull()
        ->and(PhaseWarnings::tooManyEmphasized(PhaseType::FatLoss, $three))->toBeNull();
});

it('PH-011 specialising during a cut warns', function () {
    $mixed = ['chest' => Priority::Emphasize, 'back' => Priority::Grow];
    $maintain = ['chest' => Priority::Emphasize, 'back' => Priority::Maintain];
    $allEmphasize = ['chest' => Priority::Emphasize, 'back' => Priority::Emphasize];
    $noEmphasis = ['chest' => Priority::Grow, 'back' => Priority::Maintain];
    foreach ([PhaseType::FatLoss, PhaseType::MiniCut] as $type) {
        expect(PhaseWarnings::specialisingDuringCut($type, $mixed))->toEqual(PhaseWarning::specialisingDuringCut())
            ->and(PhaseWarnings::specialisingDuringCut($type, $maintain))->toEqual(PhaseWarning::specialisingDuringCut())
            ->and(PhaseWarnings::specialisingDuringCut($type, $allEmphasize))->toBeNull()
            ->and(PhaseWarnings::specialisingDuringCut($type, $noEmphasis))->toBeNull();
    }
    expect(PhaseWarnings::specialisingDuringCut(PhaseType::MuscleGain, $mixed))->toBeNull()
        ->and(PhaseWarning::specialisingDuringCut()->message())->toBe('Specialising during a cut works against recovery');
});

it('PH-012 low volume plan in muscle gain warns', function () {
    $warn = PhaseWarning::lowVolumeSurplus();
    $allMaintain = warningsMeso(PhaseType::MuscleGain, priorities: ['chest' => Priority::Maintain, 'back' => Priority::Maintain]);
    expect(PhaseWarnings::lowVolumePlan(PhaseType::MuscleGain, warningsMeso(PhaseType::Maintenance, resensitization: true)))->toEqual($warn)
        ->and(PhaseWarnings::lowVolumePlan(PhaseType::MuscleGain, warningsMeso(PhaseType::FatLoss)))->toEqual($warn)
        ->and(PhaseWarnings::lowVolumePlan(PhaseType::MuscleGain, warningsMeso(PhaseType::MiniCut)))->toEqual($warn)
        ->and(PhaseWarnings::lowVolumePlan(PhaseType::MuscleGain, warningsMeso(PhaseType::Maintenance)))->toEqual($warn)
        ->and(PhaseWarnings::lowVolumePlan(PhaseType::MuscleGain, $allMaintain))->toEqual($warn)
        ->and(PhaseWarnings::lowVolumePlan(PhaseType::MuscleGain, warningsMeso(PhaseType::MuscleGain)))->toBeNull()
        ->and(PhaseWarnings::lowVolumePlan(PhaseType::FatLoss, warningsMeso(PhaseType::Maintenance, resensitization: true)))->toBeNull()
        ->and($warn->message())->toBe('A surplus with a low-volume plan wastes the phase');
});

it('PH-038 mini-cut too long for the previous gain warns', function () {
    expect(PhaseWarnings::miniCutLength(previousMuscleGainWeeks: 7, miniCutWeeks: 4))->toEqual(PhaseWarning::miniCutTooLong())
        ->and(PhaseWarnings::miniCutLength(previousMuscleGainWeeks: 8, miniCutWeeks: 4))->toBeNull()
        ->and(PhaseWarnings::miniCutLength(previousMuscleGainWeeks: 12, miniCutWeeks: 4))->toBeNull();
});

it('PH-040 body fat estimate during muscle gain', function () {
    // Male, low bucket 18.5%: 14.8 kg fat at 80 kg; +6 kg gained adds 3 kg fat.
    $pct = PhaseWarnings::estimatedBodyFatPercent(Sex::Male, BodyFatBucket::Low, startWeightKg: 80, currentTrendWeightKg: 86);
    expect($pct)->toEqualWithDelta(17.8 / 86 * 100, 1e-9);

    $over = PhaseWarnings::bodyFatWarning(Sex::Male, BodyFatBucket::Low, startWeightKg: 80, currentTrendWeightKg: 86);
    expect($over?->kind)->toBe(PhaseWarningKind::BodyFatHigh)
        ->and($over?->estimatePercent)->toEqualWithDelta($pct, 1e-9)
        ->and(PhaseWarnings::bodyFatRecommendedPhases())->toBe([PhaseType::MiniCut, PhaseType::FatLoss])
        ->and(PhaseWarnings::bodyFatWarning(Sex::Male, BodyFatBucket::Low, startWeightKg: 80, currentTrendWeightKg: 83))->toBeNull()
        // Women: high bucket 31%; 70 kg -> 76 kg gives 24.7 / 76 = 32.5%.
        ->and(PhaseWarnings::bodyFatWarning(Sex::Female, BodyFatBucket::High, startWeightKg: 70, currentTrendWeightKg: 76))->not->toBeNull()
        ->and(PhaseWarnings::bodyFatWarning(Sex::Female, BodyFatBucket::Low, startWeightKg: 70, currentTrendWeightKg: 76))->toBeNull()
        ->and(PhaseWarnings::bodyFatLimitPercent(Sex::Male))->toBe(20.0)
        ->and(PhaseWarnings::bodyFatLimitPercent(Sex::Female))->toBe(30.0);
});

it('PH-042 phase running past its event warns', function () {
    $phase = fn (DateTimeImmutable $end, ?DateTimeImmutable $event) => new Phase(
        id: 'p', type: PhaseType::FatLoss, start: utcDate(2026, 1, 1), end: $end, targetRatePercentPerWeek: -0.5, eventDate: $event,
    );
    expect(PhaseWarnings::runsPastEvent($phase(utcDate(2026, 6, 20), utcDate(2026, 6, 1))))->toEqual(PhaseWarning::runsPastEvent())
        ->and(PhaseWarnings::runsPastEvent($phase(utcDate(2026, 5, 20), utcDate(2026, 6, 1))))->toBeNull()
        ->and(PhaseWarnings::runsPastEvent($phase(utcDate(2026, 6, 1), utcDate(2026, 6, 1))))->toBeNull()
        ->and(PhaseWarnings::runsPastEvent($phase(utcDate(2026, 6, 20), null)))->toBeNull();
});

it('PH-043 days to event', function () {
    $phase = fn (?DateTimeImmutable $event) => new Phase(
        id: 'p', type: PhaseType::FatLoss, start: utcDate(2026, 1, 1), end: utcDate(2026, 3, 1), targetRatePercentPerWeek: -0.5, eventDate: $event,
    );
    $today = utcDate(2026, 2, 1);
    expect(PhaseWarnings::daysToEvent($phase(utcDate(2026, 3, 1)), $today))->toBe(28)
        ->and(PhaseWarnings::daysToEvent($phase($today), $today))->toBe(0)
        ->and(PhaseWarnings::daysToEvent($phase(utcDate(2026, 1, 31)), $today))->toBeNull()
        ->and(PhaseWarnings::daysToEvent($phase(null), $today))->toBeNull();
});

it('PH-045 suggests maintenance after five months without it', function () {
    $today = utcDate(2026, 10, 10);
    $phase = fn (PhaseType $type, DateTimeImmutable $s, DateTimeImmutable $e) => new Phase(
        id: 'p', type: $type, start: $s, end: $e, targetRatePercentPerWeek: 0,
    );
    $gain = $phase(PhaseType::MuscleGain, utcDate(2026, 1, 1), utcDate(2026, 9, 1));
    expect(PhaseWarnings::maintenanceSuggestion([$gain], $today))->toEqual(new MaintenanceSuggestion(weeks: 4, isResensitization: true))
        // No plan yet, or a plan younger than 5 months, has nothing to suggest.
        ->and(PhaseWarnings::maintenanceSuggestion([], $today))->toBeNull()
        ->and(PhaseWarnings::maintenanceSuggestion([$phase(PhaseType::MuscleGain, utcDate(2026, 9, 10), utcDate(2026, 10, 30))], $today))->toBeNull()
        // A plan that began exactly 5 months ago qualifies.
        ->and(PhaseWarnings::maintenanceSuggestion([$phase(PhaseType::MuscleGain, utcDate(2026, 5, 10), utcDate(2026, 10, 30))], $today))->not->toBeNull()
        // Maintenance that ended 4 months ago is inside the 5 month window.
        ->and(PhaseWarnings::maintenanceSuggestion([$gain, $phase(PhaseType::Maintenance, utcDate(2026, 5, 1), utcDate(2026, 6, 10))], $today))->toBeNull()
        // Maintenance that ended 6 months ago is not.
        ->and(PhaseWarnings::maintenanceSuggestion([$gain, $phase(PhaseType::Maintenance, utcDate(2026, 3, 1), utcDate(2026, 4, 10))], $today))->not->toBeNull()
        // Maintenance running now counts.
        ->and(PhaseWarnings::maintenanceSuggestion([$phase(PhaseType::Maintenance, utcDate(2026, 10, 1), utcDate(2026, 11, 1))], $today))->toBeNull()
        // Maintenance planned for later does not.
        ->and(PhaseWarnings::maintenanceSuggestion([$gain, $phase(PhaseType::Maintenance, utcDate(2026, 11, 1), utcDate(2026, 12, 1))], $today))->not->toBeNull();
});

it('PH-045 five months back from the 31st ends on the last day of the shorter month, as a calendar does', function () {
    // Swift's Calendar clamps 31 July - 5 months to 28 February; PHP's modify('-5 months') would give 3 March.
    $today = utcDate(2026, 7, 31);
    $gain = fn (DateTimeImmutable $start) => new Phase(
        id: 'p', type: PhaseType::MuscleGain, start: $start, end: utcDate(2026, 9, 1), targetRatePercentPerWeek: 0.25,
    );
    expect(PhaseWarnings::maintenanceSuggestion([$gain(utcDate(2026, 2, 28))], $today))->not->toBeNull()
        ->and(PhaseWarnings::maintenanceSuggestion([$gain(utcDate(2026, 3, 1))], $today))->toBeNull();
});

it('PH-005 open-ended maintenance in event and suggestion checks', function () {
    $today = utcDate(2026, 10, 10);
    $open = new Phase(
        id: 'p', type: PhaseType::Maintenance, start: utcDate(2026, 1, 1), end: null, targetRatePercentPerWeek: 0,
        eventDate: utcDate(2026, 6, 1),
    );
    expect(PhaseWarnings::runsPastEvent($open))->toBeNull()
        ->and(PhaseWarnings::maintenanceSuggestion([$open], $today))->toBeNull();
});
