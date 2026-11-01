<?php

use App\Domain\Model\UnitConversion;

it('SET-001 uses exact factors', function () {
    expect(UnitConversion::lbToKg(1))->toEqualWithDelta(0.45359237, 1e-12)
        ->and(UnitConversion::kgToLb(0.45359237))->toEqualWithDelta(1, 1e-12)
        ->and(UnitConversion::feetInchesToCm(0, 1))->toEqualWithDelta(2.54, 1e-12)
        ->and(UnitConversion::feetInchesToCm(6, 0))->toEqualWithDelta(182.88, 1e-9);
});

it('SET-001 round trips kg through lb', function () {
    for ($kg = 30.0; $kg <= 250.0; $kg += 0.37) {
        expect(UnitConversion::lbToKg(UnitConversion::kgToLb($kg)))->toEqualWithDelta($kg, 0.01);
    }
});

it('SET-001 round trips cm through feet and inches', function () {
    for ($cm = 120.0; $cm <= 230.0; $cm += 0.41) {
        [$feet, $inches] = UnitConversion::cmToFeetInches($cm);

        expect($inches)->toBeGreaterThanOrEqual(0)->toBeLessThan(12)
            ->and(UnitConversion::feetInchesToCm($feet, $inches))->toEqualWithDelta($cm, 0.01);
    }
});

it('SET-001 converts a known height', function () {
    [$feet, $inches] = UnitConversion::cmToFeetInches(177.8);

    expect($feet)->toBe(5)
        ->and($inches)->toEqualWithDelta(10, 1e-9);
});

it('SET-001 never reads a whole number of feet as almost the foot below', function () {
    // 152.4 cm is exactly 60 in.
    [$feet, $inches] = UnitConversion::cmToFeetInches(152.4);

    expect($feet)->toBe(5)
        ->and($inches)->toEqualWithDelta(0, 1e-9);
});
