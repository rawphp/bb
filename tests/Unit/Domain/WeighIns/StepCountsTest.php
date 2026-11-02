<?php

use App\Domain\WeighIns\StepCounts;
use App\Enums\MeasurementSource;

it('WT-020 lets a typed count replace any count for the day', function () {
    expect(StepCounts::replaces(null, MeasurementSource::Manual))->toBeTrue()
        ->and(StepCounts::replaces(MeasurementSource::Manual, MeasurementSource::Manual))->toBeTrue()
        ->and(StepCounts::replaces(MeasurementSource::Shortcut, MeasurementSource::Manual))->toBeTrue();
});

it('WT-020 never lets the Shortcuts bridge overwrite a typed count, but a newer bridge count replaces a bridge count', function () {
    expect(StepCounts::replaces(MeasurementSource::Manual, MeasurementSource::Shortcut))->toBeFalse()
        ->and(StepCounts::replaces(MeasurementSource::Shortcut, MeasurementSource::Shortcut))->toBeTrue()
        ->and(StepCounts::replaces(null, MeasurementSource::Shortcut))->toBeTrue();
});

it('WT-020 offers yesterday by default, since today\'s count is not final', function () {
    $today = new DateTimeImmutable('2026-10-10 00:00', new DateTimeZone('UTC'));

    expect(StepCounts::defaultDate($today)->format('Y-m-d'))->toBe('2026-10-09');
});

it('WT-020 says where a count came from', function () {
    expect(StepCounts::sourceLabel(MeasurementSource::Manual))->toBe('Typed')
        ->and(StepCounts::sourceLabel(MeasurementSource::Shortcut))->toBe('Shortcut');
});
