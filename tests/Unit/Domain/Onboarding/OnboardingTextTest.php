<?php

use App\Domain\Model\HeightUnit;
use App\Domain\Model\WeightUnit;
use App\Domain\Onboarding\OnboardingText;
use Tests\Unit\Domain\Onboarding\OnboardingFixtures as F;

// The words the review and the steps write (rp-clone OnboardingModel+Review's text helpers, OnboardingReviewTests):
// the same on every device, whatever its locale.

it('ONB-018 writes a date as "Oct 11, 2026"', function () {
    expect(OnboardingText::dateText(F::date(2026, 10, 11)))->toBe('Oct 11, 2026')
        ->and(OnboardingText::dateText(F::date(1990, 4, 9)))->toBe('Apr 9, 1990')
        ->and(OnboardingText::dateText(F::date(2027, 1, 1)))->toBe('Jan 1, 2027');
});

it('ONB-018 writes a time of day as "6:00 PM", kept inside the day', function () {
    expect(OnboardingText::timeText(18 * 60))->toBe('6:00 PM')
        ->and(OnboardingText::timeText(6 * 60 + 30))->toBe('6:30 AM')
        ->and(OnboardingText::timeText(0))->toBe('12:00 AM')
        ->and(OnboardingText::timeText(12 * 60))->toBe('12:00 PM')
        ->and(OnboardingText::timeText(5000))->toBe('11:59 PM')
        ->and(OnboardingText::timeText(-5))->toBe('12:00 AM');
});

it('ONB-018 groups numbers the US way and writes kcal a day', function () {
    expect(OnboardingText::numberText(5000))->toBe('5,000')
        ->and(OnboardingText::numberText(0))->toBe('0')
        ->and(OnboardingText::numberText(1234567))->toBe('1,234,567')
        ->and(OnboardingText::numberText(-8000))->toBe('-8,000')
        ->and(OnboardingText::kcalText(1367.6))->toBe('1,368 kcal a day');
});

it('ONB-018 joins runs of neighbouring weekdays', function () {
    expect(OnboardingText::weekdaysText([1, 2, 3, 4, 5]))->toBe('Mon to Fri')
        ->and(OnboardingText::weekdaysText([6, 7]))->toBe('Sat and Sun')
        ->and(OnboardingText::weekdaysText([5, 1, 3]))->toBe('Mon, Wed, Fri')
        ->and(OnboardingText::weekdaysText([1, 2, 4, 5, 6]))->toBe('Mon and Tue, Thu to Sat')
        ->and(OnboardingText::weekdaysText([]))->toBe('');
});

it('ONB-016 names weekdays short and in full, Monday first', function () {
    expect(array_map(OnboardingText::weekdayName(...), range(1, 7)))->toBe(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'])
        ->and(OnboardingText::weekdayFullName(6))->toBe('Saturday')
        ->and(OnboardingText::weekdayFullName(7))->toBe('Sunday');
});

it('ONB-018 writes the weekly rate with its TBL-010 sign', function () {
    expect(OnboardingText::rateText(-0.75))->toBe('-0.75%')
        ->and(OnboardingText::rateText(0.375))->toBe('+0.375%')
        ->and(OnboardingText::rateText(0.0))->toBe('0%');
});

it('ONB-018 writes weights and heights in the user\'s unit', function () {
    expect(OnboardingText::weightText(80, WeightUnit::Kg))->toBe('80 kg')
        ->and(OnboardingText::weightText(72.8, WeightUnit::Kg))->toBe('72.8 kg')
        ->and(OnboardingText::weightText(80, WeightUnit::Lb))->toBe('176.4 lb')
        ->and(OnboardingText::weightText(72.8, WeightUnit::Lb))->toBe('160.5 lb')
        ->and(OnboardingText::heightText(170, HeightUnit::Cm))->toBe('170 cm')
        ->and(OnboardingText::heightText(170.5, HeightUnit::Cm))->toBe('170.5 cm')
        ->and(OnboardingText::heightText(170, HeightUnit::FeetInches))->toBe('5 ft 7 in')
        ->and(OnboardingText::heightText(182.6, HeightUnit::FeetInches))->toBe('6 ft 0 in');
});

it('ONB-004 converts a weight between kg and the unit on screen', function () {
    expect(OnboardingText::weightIn(80, WeightUnit::Kg))->toBe(80.0)
        ->and(OnboardingText::weightIn(80, WeightUnit::Lb))->toEqualWithDelta(80 / 0.45359237, 0.0001)
        ->and(OnboardingText::kgFrom(176, WeightUnit::Lb))->toEqualWithDelta(176 * 0.45359237, 0.0001)
        ->and(OnboardingText::kgFrom(80, WeightUnit::Kg))->toBe(80.0);
});
