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

function utcDate(int $y, int $m, int $d, int $hour = 0): DateTimeImmutable
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->setDate($y, $m, $d)->setTime($hour, 0);
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
        ->and(PhaseWarnings::tooManyEmphasized(PhaseType::FatLoss, $three))->toBeNull()
        ->and(PhaseWarning::tooManyEmphasized()->message())->toBe('Specialising more than 2 muscle groups spreads recovery too thin');
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
        // No priorities set is not "every muscle at Maintain".
        ->and(PhaseWarnings::lowVolumePlan(PhaseType::MuscleGain, warningsMeso(PhaseType::MuscleGain, priorities: [])))->toBeNull()
        ->and($warn->message())->toBe('A surplus with a low-volume plan wastes the phase');
});

it('PH-038 mini-cut too long for the previous gain warns', function () {
    expect(PhaseWarnings::miniCutLength(previousMuscleGainWeeks: 7, miniCutWeeks: 4))->toEqual(PhaseWarning::miniCutTooLong())
        ->and(PhaseWarnings::miniCutLength(previousMuscleGainWeeks: 8, miniCutWeeks: 4))->toBeNull()
        ->and(PhaseWarnings::miniCutLength(previousMuscleGainWeeks: 12, miniCutWeeks: 4))->toBeNull()
        ->and(PhaseWarnings::miniCutLength(previousMuscleGainWeeks: 8, miniCutWeeks: 0))->toBeNull()
        ->and(PhaseWarning::miniCutTooLong()->message())
        ->toBe('This mini-cut is long for the gain before it. Aim for at least 2:1 gain to mini-cut');
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
        ->and(PhaseWarnings::bodyFatLimitPercent(Sex::Female))->toBe(30.0)
        // The message rounds the estimate to a whole percent.
        ->and(PhaseWarning::bodyFatHigh(20.7)->message())->toBe('Estimated body fat is about 21%. Consider a Mini-cut or Fat loss')
        ->and(PhaseWarning::bodyFatHigh(20.4)->message())->toBe('Estimated body fat is about 20%. Consider a Mini-cut or Fat loss');
});

it('PH-042 phase running past its event warns', function () {
    $phase = fn (DateTimeImmutable $end, ?DateTimeImmutable $event) => new Phase(
        id: 'p', type: PhaseType::FatLoss, start: utcDate(2026, 1, 1), end: $end, targetRatePercentPerWeek: -0.5, eventDate: $event,
    );
    expect(PhaseWarnings::runsPastEvent($phase(utcDate(2026, 6, 20), utcDate(2026, 6, 1))))->toEqual(PhaseWarning::runsPastEvent())
        ->and(PhaseWarnings::runsPastEvent($phase(utcDate(2026, 5, 20), utcDate(2026, 6, 1))))->toBeNull()
        ->and(PhaseWarnings::runsPastEvent($phase(utcDate(2026, 6, 1), utcDate(2026, 6, 1))))->toBeNull()
        ->and(PhaseWarnings::runsPastEvent($phase(utcDate(2026, 6, 20), null)))->toBeNull()
        ->and(PhaseWarning::runsPastEvent()->message())->toBe('This phase ends after your event date');
});

it('PH-042 reads end as the day after the last week, so finishing on the event day does not warn', function () {
    // Eight weeks from Mon 5 Jan 2026: the last day is Sun 1 Mar, so end is Mon 2 Mar.
    $phase = fn (DateTimeImmutable $event) => new Phase(
        id: 'p', type: PhaseType::FatLoss, start: utcDate(2026, 1, 5), end: utcDate(2026, 3, 2), targetRatePercentPerWeek: -0.5,
        eventDate: $event,
    );
    expect(PhaseWarnings::runsPastEvent($phase(utcDate(2026, 3, 1))))->toBeNull()
        ->and(PhaseWarnings::runsPastEvent($phase(utcDate(2026, 2, 28))))->toEqual(PhaseWarning::runsPastEvent());
});

it('PH-042 compares the end and event days in the given zone', function () {
    // end 2 June 13:00 UTC is 3 June in Auckland (UTC+12), so the last day there is 2 June; the event, 1 June
    // 00:00 UTC, is still 1 June there. In UTC the last day is 1 June, the event's own day.
    $phase = new Phase(
        id: 'p', type: PhaseType::FatLoss, start: utcDate(2026, 1, 1), end: utcDate(2026, 6, 2, 13), targetRatePercentPerWeek: -0.5,
        eventDate: utcDate(2026, 6, 1),
    );
    expect(PhaseWarnings::runsPastEvent($phase, new DateTimeZone('Pacific/Auckland')))->toEqual(PhaseWarning::runsPastEvent())
        ->and(PhaseWarnings::runsPastEvent($phase))->toBeNull();
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

it('PH-043 counts days in the given zone, whatever the time of day', function () {
    $phase = new Phase(
        id: 'p', type: PhaseType::FatLoss, start: utcDate(2026, 1, 1), end: utcDate(2026, 7, 1), targetRatePercentPerWeek: -0.5,
        eventDate: utcDate(2026, 6, 1),
    );
    // 31 May 13:00 UTC is 1 June 01:00 in Auckland, the event's own day there.
    expect(PhaseWarnings::daysToEvent($phase, utcDate(2026, 5, 31, 13), new DateTimeZone('Pacific/Auckland')))->toBe(0)
        ->and(PhaseWarnings::daysToEvent($phase, utcDate(2026, 5, 31, 13)))->toBe(1)
        ->and(PhaseWarnings::daysToEvent($phase, utcDate(2026, 5, 31, 23)))->toBe(1);
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

it('PH-045 five months back from January to May lands in the year before', function () {
    $gain = fn (DateTimeImmutable $start) => new Phase(
        id: 'p', type: PhaseType::MuscleGain, start: $start, end: utcDate(2026, 9, 1), targetRatePercentPerWeek: 0.25,
    );
    expect(PhaseWarnings::maintenanceSuggestion([$gain(utcDate(2025, 10, 31))], utcDate(2026, 3, 31)))->not->toBeNull()
        ->and(PhaseWarnings::maintenanceSuggestion([$gain(utcDate(2025, 11, 1))], utcDate(2026, 3, 31)))->toBeNull()
        ->and(PhaseWarnings::maintenanceSuggestion([$gain(utcDate(2025, 9, 28))], utcDate(2026, 2, 28)))->not->toBeNull()
        ->and(PhaseWarnings::maintenanceSuggestion([$gain(utcDate(2025, 9, 29))], utcDate(2026, 2, 28)))->toBeNull()
        ->and(PhaseWarnings::maintenanceSuggestion([$gain(utcDate(2025, 8, 15))], utcDate(2026, 1, 15)))->not->toBeNull()
        ->and(PhaseWarnings::maintenanceSuggestion([$gain(utcDate(2025, 8, 16))], utcDate(2026, 1, 15)))->toBeNull();
});

it('PH-045 compares calendar days, so the time of day moves nothing', function () {
    $phase = fn (PhaseType $type, DateTimeImmutable $s, DateTimeImmutable $e) => new Phase(
        id: 'p', type: $type, start: $s, end: $e, targetRatePercentPerWeek: 0,
    );
    $gain = $phase(PhaseType::MuscleGain, utcDate(2026, 1, 1), utcDate(2026, 9, 1));
    // A maintenance whose last day is the window's first day (end 11 May, exclusive) is inside it, even when today
    // is read at 14:00.
    $maintenance = $phase(PhaseType::Maintenance, utcDate(2026, 3, 1), utcDate(2026, 5, 11));
    expect(PhaseWarnings::maintenanceSuggestion([$gain, $maintenance], utcDate(2026, 10, 10, 14)))->toBeNull()
        // One whose end is 10 May, so its last day is 9 May, is outside it, whatever the end's time of day.
        ->and(PhaseWarnings::maintenanceSuggestion(
            [$gain, $phase(PhaseType::Maintenance, utcDate(2026, 3, 1), utcDate(2026, 5, 10, 5))], utcDate(2026, 10, 10, 14),
        ))->not->toBeNull()
        // A plan that began later in the day exactly 5 months ago still qualifies on a morning today.
        ->and(PhaseWarnings::maintenanceSuggestion(
            [$phase(PhaseType::MuscleGain, utcDate(2026, 5, 10, 15), utcDate(2026, 10, 30))], utcDate(2026, 10, 10, 9),
        ))->not->toBeNull()
        // A maintenance starting later today is running today.
        ->and(PhaseWarnings::maintenanceSuggestion(
            [$gain, $phase(PhaseType::Maintenance, utcDate(2026, 10, 10, 18), utcDate(2026, 11, 1))], utcDate(2026, 10, 10, 9),
        ))->toBeNull();
});

it('PH-045 counts the window in the given zone', function () {
    // 10 Oct 12:00 UTC is 11 Oct in Auckland (UTC+13), so the window starts on 11 May there and on 10 May in UTC.
    $plan = [new Phase(
        id: 'p', type: PhaseType::MuscleGain, start: utcDate(2026, 5, 11), end: utcDate(2026, 10, 30), targetRatePercentPerWeek: 0.25,
    )];
    expect(PhaseWarnings::maintenanceSuggestion($plan, utcDate(2026, 10, 10, 12), new DateTimeZone('Pacific/Auckland')))->not->toBeNull()
        ->and(PhaseWarnings::maintenanceSuggestion($plan, utcDate(2026, 10, 10, 12)))->toBeNull();
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
