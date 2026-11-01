<?php

use App\Domain\Defaults\TBL032CarbSanityBounds;
use App\Domain\Model\ClosedRange;
use App\Domain\Model\DayType;

it('TBL-032 carb bounds by day type', function (DayType $type, ?ClosedRange $bounds) {
    expect(TBL032CarbSanityBounds::gramsPerLbLeanMass($type))->toEqual($bounds);
})->with([
    'rest' => [DayType::Rest, new ClosedRange(0.5, 1.0)],
    'light' => [DayType::Light, new ClosedRange(1.5, 3.0)],
    'moderate' => [DayType::Moderate, new ClosedRange(1.5, 3.0)],
    'hard' => [DayType::Hard, new ClosedRange(1.5, 3.0)],
    'deload, not in the table' => [DayType::Deload, null],
]);

it('TBL-032 warns after two weeks running', function () {
    expect(TBL032CarbSanityBounds::WARN_AFTER_WEEKS_RUNNING)->toBe(2);
});
