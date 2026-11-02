<?php

use App\Domain\Progress\DayStatus;
use App\Domain\Progress\DayTotals;

// rp-clone has no test for DayStatus; these follow its doc comment and DAY-010.

it('DAY-010 within 10% on calories and protein is on track', function () {
    $status = DayStatus::make(new DayTotals(2200, 135), new DayTotals(2000, 150));
    expect($status->text)->toBe('on track')
        ->and($status->onTrack)->toBeTrue()
        ->and(DayStatus::ON_TRACK_TEXT)->toBe('on track');
});

it('DAY-010 calories off says how far under or over', function () {
    $under = DayStatus::make(new DayTotals(1685, 150), new DayTotals(2000, 150));
    $over = DayStatus::make(new DayTotals(2400.4, 0), new DayTotals(2000, 150));
    expect($under->text)->toBe('315 under')
        ->and($under->onTrack)->toBeFalse()
        // Calories are named first, even when protein is off too.
        ->and($over->text)->toBe('400 over');
});

it('DAY-010 only protein off names protein', function () {
    expect(DayStatus::make(new DayTotals(2000, 130), new DayTotals(2000, 150))->text)->toBe('20 g protein under')
        ->and(DayStatus::make(new DayTotals(2000, 170), new DayTotals(2000, 150))->text)->toBe('20 g protein over');
});
