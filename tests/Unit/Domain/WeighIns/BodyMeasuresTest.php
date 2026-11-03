<?php

use App\Domain\WeighIns\BodyMeasures;

// Ported from rp-clone ios/Tests/RPCoreTests/Features/WeighIns/WeighInEntryModelTests.swift and
// WeighInsModelTests.swift (display). The client sends the number it was given in the user's unit;
// the server converts and rounds (CAP-015).

it('WT-002 stores a weight in kg to 2 decimals, converting pounds', function () {
    expect(BodyMeasures::kg(82.5, 'kg'))->toBe(82.5)
        ->and(BodyMeasures::kg(180, 'lb'))->toBe(81.65)
        ->and(BodyMeasures::kg(82.456, 'kg'))->toBe(82.46);
});

it('WT-002 needs a weight up to 999.99 kg, in kg and not in the pounds typed', function () {
    expect(BodyMeasures::kg(999.99, 'kg'))->toBe(999.99)
        ->and(BodyMeasures::kg(1000, 'lb'))->toBe(453.59);

    foreach ([[1000, 'kg'], [999.995, 'kg'], [2300, 'lb'], [0.004, 'kg'], [0, 'kg'], [-5, 'kg']] as [$value, $unit]) {
        expect(fn () => BodyMeasures::kg($value, $unit))->toThrow(DomainException::class, 'Enter a weight above 0 and up to 999.99 kg.');
    }
});

it('WT-011 shows a weight to one decimal in the user\'s unit', function () {
    expect(BodyMeasures::weightLabel(81.4, 'kg'))->toBe('81.4 kg')
        ->and(BodyMeasures::weightLabel(82, 'kg'))->toBe('82.0 kg')
        ->and(BodyMeasures::weightLabel(82.35, 'lb'))->toBe('181.6 lb');
});

it('WT-004 starts the edit field from the weigh-in, in the user\'s unit, without trailing zeros', function () {
    expect(BodyMeasures::weightField(81.4, 'kg'))->toBe('81.4')
        ->and(BodyMeasures::weightField(82, 'kg'))->toBe('82')
        ->and(BodyMeasures::weightField(81.65, 'kg'))->toBe('81.65')
        ->and(BodyMeasures::weightField(90.72, 'lb'))->toBe('200');
});

it('WT-021 stores a waist in cm to one decimal, converting inches', function () {
    expect(BodyMeasures::waistCm(86, 'cm'))->toBe(86.0)
        ->and(BodyMeasures::waistCm(86.04, 'cm'))->toBe(86.0)
        ->and(BodyMeasures::waistCm(34, 'in'))->toBe(86.4);
});

it('WT-021 shows a waist in the height unit\'s family, to one decimal without a trailing zero', function () {
    expect(BodyMeasures::waistUnit('cm'))->toBe('cm')
        ->and(BodyMeasures::waistUnit('feetInches'))->toBe('in')
        ->and(BodyMeasures::waistLabel(86, 'cm'))->toBe('86 cm')
        ->and(BodyMeasures::waistLabel(86.5, 'cm'))->toBe('86.5 cm')
        ->and(BodyMeasures::waistLabel(86.4, 'in'))->toBe('34 in')
        ->and(BodyMeasures::waistField(86.5, 'cm'))->toBe('86.5')
        ->and(BodyMeasures::waistField(86.4, 'in'))->toBe('34');
});
