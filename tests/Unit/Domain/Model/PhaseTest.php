<?php

use App\Domain\Model\Phase;
use App\Domain\Model\PhaseType;

it('PLAN-002 a phase moved to new dates keeps every other field', function () {
    $utc = new DateTimeZone('UTC');
    $phase = new Phase(
        id: 'cut',
        type: PhaseType::FatLoss,
        start: new DateTimeImmutable('2026-01-05', $utc),
        end: new DateTimeImmutable('2026-03-02', $utc),
        targetRatePercentPerWeek: -0.75,
        eventDate: new DateTimeImmutable('2026-04-01', $utc),
    );
    $start = new DateTimeImmutable('2026-01-12', $utc);

    $moved = $phase->withDates($start, null);

    // Every field but the two dates, read by reflection so a field added later is checked too.
    $rest = fn (Phase $p) => array_diff_key(get_object_vars($p), ['start' => 0, 'end' => 0]);
    expect($moved->start)->toBe($start)
        ->and($moved->end)->toBeNull()
        ->and($rest($moved))->toBe($rest($phase));
});
