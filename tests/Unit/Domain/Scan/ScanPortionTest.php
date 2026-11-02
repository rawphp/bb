<?php

use App\Domain\Scan\ScanPortion;

it('FOOD-045 scanned grams are to a tenth and never below one tenth', function () {
    expect(ScanPortion::grams(187.54))->toBe(187.5)
        ->and(ScanPortion::grams(187.55))->toBe(187.6)
        ->and(ScanPortion::grams(40))->toBe(40.0)
        ->and(ScanPortion::grams(0.04))->toBe(0.1)
        ->and(ScanPortion::grams(0.0001))->toBe(0.1)
        ->and(ScanPortion::grams(0))->toBe(0.1);
});

it('FOOD-045 the portion is written as a multiple: 1×, 1.25×, 0.75×, 4×', function () {
    expect(ScanPortion::text(1.0))->toBe('1×')
        ->and(ScanPortion::text(1.25))->toBe('1.25×')
        ->and(ScanPortion::text(1.5))->toBe('1.5×')
        ->and(ScanPortion::text(0.75))->toBe('0.75×')
        ->and(ScanPortion::text(4.0))->toBe('4×');
});

it('FOOD-045 plus and minus move in quarter steps', function () {
    expect(ScanPortion::up(1.0))->toBe(1.25)
        ->and(ScanPortion::up(1.25))->toBe(1.5)
        ->and(ScanPortion::down(1.0))->toBe(0.75)
        ->and(ScanPortion::down(0.75))->toBe(0.5);
});

it('FOOD-045 the portion stops at a quarter and at four times', function () {
    expect(ScanPortion::down(0.25))->toBeNull()
        ->and(ScanPortion::up(0.25))->toBe(0.5)
        ->and(ScanPortion::up(4.0))->toBeNull()
        ->and(ScanPortion::down(4.0))->toBe(3.75)
        ->and(ScanPortion::up(3.75))->toBe(4.0)
        ->and(ScanPortion::down(0.5))->toBe(0.25);
});

it('FOOD-045 the grams at one times are the base the screen sent back, while the row shows what that base gives', function () {
    // 33 g at 1×, stepped up one quarter at a time, rounds once from the base, as rp-clone does: 57.8 g at 1.75×.
    $base = 33.0;
    foreach ([1.0, 1.25, 1.5] as $from) {
        $shown = ScanPortion::grams($base * $from);
        $base = ScanPortion::base($shown, $base, $from);
    }

    expect($base)->toBe(33.0)
        ->and(ScanPortion::grams($base * 1.75))->toBe(57.8);
});

it('FOOD-045 a round trip through the smallest portion comes back to the same grams', function () {
    // A pinch of salt shows 0.1 g at 0.25×, the floor, and is back to 0.1 g at 1×.
    $base = ScanPortion::base(ScanPortion::grams(0.1 * 0.25), 0.1, 0.25);

    expect($base)->toBe(0.1)
        ->and(ScanPortion::grams($base * 1.0))->toBe(0.1);
});

it('FOOD-045 a typed amount is the amount shown, so it is kept across portions', function () {
    // 100 g typed at 1.25× over a row whose base showed 250 g is 80 g at 1×.
    expect(ScanPortion::base(100, 200, 1.25))->toBe(80.0)
        ->and(ScanPortion::grams(ScanPortion::base(100, 200, 1.25) * 1.25))->toBe(100.0);
});

it('FOOD-045 a row sent back with no base is taken as typed at its portion', function () {
    expect(ScanPortion::base(100, null, 1.25))->toBe(80.0)
        ->and(ScanPortion::base(37.5, null, 1.0))->toBe(37.5);
});
