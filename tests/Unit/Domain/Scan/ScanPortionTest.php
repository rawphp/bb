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

it('FOOD-045 the shown grams follow the portion', function () {
    // 200 g at 1× is 250 g at 1.25×, and 150 g is 187.5 g.
    expect(ScanPortion::rescale(200, 1.0, 1.25))->toBe(250.0)
        ->and(ScanPortion::rescale(150, 1.0, 1.25))->toBe(187.5)
        ->and(ScanPortion::rescale(30, 1.0, 1.25))->toBe(37.5);
});

it('FOOD-045 a typed amount is the amount shown, so it is kept across portions', function () {
    // 100 g typed at 1.25× is 80 g back at 1×.
    expect(ScanPortion::rescale(100, 1.25, 1.25))->toBe(100.0)
        ->and(ScanPortion::rescale(100, 1.25, 1.0))->toBe(80.0);
});

it('FOOD-045 the scaled grams are rounded to a tenth and never zero', function () {
    expect(ScanPortion::rescale(33, 1.0, 1.75))->toBe(57.8)
        ->and(ScanPortion::rescale(0.1, 1.0, 0.25))->toBe(0.1);
});
