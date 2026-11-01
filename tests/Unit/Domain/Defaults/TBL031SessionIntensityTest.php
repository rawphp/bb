<?php

use App\Domain\Defaults\TBL031SessionIntensity;
use App\Domain\Model\ClosedRange;
use App\Domain\Model\DayType;

it('TBL-031 deload week is light whatever the sets', function () {
    expect(TBL031SessionIntensity::DELOAD_WEEK_INTENSITY)->toBe(DayType::Light);
});

it('TBL-031 under 10 sets is light', function () {
    expect(TBL031SessionIntensity::LIGHT_BELOW_SETS)->toBe(10)
        ->and(TBL031SessionIntensity::LIGHT_INTENSITY)->toBe(DayType::Light);
});

it('TBL-031 10 to 19 sets is moderate', function () {
    expect(TBL031SessionIntensity::moderateSets())->toEqual(new ClosedRange(10, 19))
        ->and(TBL031SessionIntensity::MODERATE_INTENSITY)->toBe(DayType::Moderate);
});

it('TBL-031 20 or more sets, or over 75 minutes, is hard', function () {
    expect(TBL031SessionIntensity::HARD_FROM_SETS)->toBe(20)
        ->and(TBL031SessionIntensity::HARD_OVER_ESTIMATED_MINUTES)->toBe(75)
        ->and(TBL031SessionIntensity::HARD_INTENSITY)->toBe(DayType::Hard);
});
