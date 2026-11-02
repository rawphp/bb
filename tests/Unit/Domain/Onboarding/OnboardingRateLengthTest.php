<?php

use App\Domain\Model\ClosedRange;
use App\Domain\Model\PhaseType;
use App\Domain\Model\WeightUnit;
use App\Domain\Onboarding\Draft;
use App\Domain\Onboarding\OnboardingRateLength;
use App\Domain\Onboarding\RateOption;
use Tests\Unit\Domain\Onboarding\OnboardingFixtures as F;

// Step 6, rate and length (ONB-008, ONB-009, TBL-010), the read cases of rp-clone's OnboardingRateLengthTests. The
// person weighs 80 kg and starts tomorrow, Sunday 11 October 2026. Setting the start, the last day, the length or the
// target is #67's write; here the draft already holds the answer and the rules read it.

function rateLength(Draft $draft): OnboardingRateLength
{
    return new OnboardingRateLength($draft, F::today());
}

/** @return list<string> */
function rateOptionNames(Draft $draft): array
{
    return array_map(fn (RateOption $option) => $option->name, rateLength($draft)->rateOptions());
}

// ONB-008 presets and defaults

it('ONB-008 Fat loss offers Highest success, Slow & steady and Custom, with Highest success the default', function () {
    $draft = F::atStep6(PhaseType::FatLoss);

    expect(rateOptionNames($draft))->toBe(['Highest success', 'Slow & steady', 'Custom'])
        ->and(array_map(fn (RateOption $o) => $o->ratePercentPerWeek, rateLength($draft)->rateOptions()))->toBe([-0.75, -0.5, null])
        ->and(rateLength($draft)->selectedRateOptionName())->toBe('Highest success');
});

it('ONB-008 Muscle gain offers the three experience presets by name, and Custom', function () {
    $draft = F::atStep6(PhaseType::MuscleGain);

    expect(rateOptionNames($draft))->toBe(['Beginner 0.5%', 'Intermediate 0.375%', 'Advanced 0.25%', 'Custom'])
        ->and(array_map(fn (RateOption $o) => $o->ratePercentPerWeek, rateLength($draft)->rateOptions()))->toBe([0.5, 0.375, 0.25, null])
        ->and(rateLength($draft)->selectedRateOptionName())->toBe('Intermediate 0.375%');
});

it('ONB-008 Maintenance offers Hold and Custom', function () {
    $draft = F::atStep6(PhaseType::Maintenance);

    expect(rateOptionNames($draft))->toBe(['Hold', 'Custom'])
        ->and(rateLength($draft)->selectedRateOptionName())->toBe('Hold');
});

it('ONB-008 no goal, no rate options', function () {
    expect(rateLength(new Draft)->rateOptions())->toBe([]);
});

it('ONB-008 each goal has its default rate and length', function () {
    expect(OnboardingRateLength::defaultRate(PhaseType::FatLoss))->toBe(-0.75)
        ->and(OnboardingRateLength::defaultWeeks(PhaseType::FatLoss))->toBe(12)
        ->and(OnboardingRateLength::defaultRate(PhaseType::MuscleGain))->toBe(0.375)
        ->and(OnboardingRateLength::defaultWeeks(PhaseType::MuscleGain))->toBe(12)
        ->and(OnboardingRateLength::defaultRate(PhaseType::Maintenance))->toBe(0.0)
        ->and(OnboardingRateLength::defaultWeeks(PhaseType::Maintenance))->toBe(4);
});

it('ONB-008 the start defaults to tomorrow, and the end and the target are worked out', function () {
    $rate = rateLength(F::atStep6(PhaseType::FatLoss));

    expect($rate->tomorrow())->toEqual(F::tomorrow())
        ->and($rate->endDate())->toEqual(F::date(2027, 1, 3))
        ->and($rate->targetWeightKg())->toEqualWithDelta(72.8, 0.0001);
});

it('ONB-008 the end date on screen is the last day of the phase, the day before the exclusive end', function () {
    $rate = rateLength(F::atStep6(PhaseType::FatLoss));

    expect($rate->lastDay())->toEqual(F::date(2027, 1, 2))
        ->and($rate->endDate())->toEqual(F::date(2027, 1, 3));
});

it('ONB-008 a later start moves the end and keeps the target', function () {
    $rate = rateLength(F::atStep6(PhaseType::FatLoss)->with(startDate: F::date(2026, 10, 14)));

    expect($rate->endDate())->toEqual(F::date(2027, 1, 6))
        ->and($rate->targetWeightKg())->toEqualWithDelta(72.8, 0.0001);
});

it('ONB-008 without a start or a length there is no end, and without a weight no target', function () {
    expect(rateLength(F::atStep6()->with(startDate: null))->lastDay())->toBeNull()
        ->and(rateLength(F::atStep6()->with(weeks: null))->endDate())->toBeNull()
        ->and(rateLength(F::atStep6()->with(weightKg: null))->targetWeightKg())->toBeNull()
        ->and(rateLength(F::atStep6()->with(ratePercentPerWeek: null))->targetWeightKg())->toBeNull();
});

it('ONB-008 a preset sets the rate and so the target', function () {
    $rate = rateLength(F::atStep6(PhaseType::FatLoss)->with(ratePercentPerWeek: -0.5));

    expect($rate->selectedRateOptionName())->toBe('Slow & steady')
        ->and($rate->targetWeightKg())->toEqualWithDelta(75.2, 0.0001);
});

it('ONB-008 a custom rate reads Custom, even one that equals a preset', function () {
    expect(rateLength(F::atStep6()->with(customRate: true))->selectedRateOptionName())->toBe('Custom')
        ->and(rateLength(F::atStep6()->with(ratePercentPerWeek: -0.5, customRate: true))->selectedRateOptionName())->toBe('Custom')
        ->and(rateLength(F::atStep6()->with(ratePercentPerWeek: -0.6))->selectedRateOptionName())->toBe('Custom');
});

it('ONB-008 the rate slider shows Fat loss as a positive percent', function () {
    $fatLoss = rateLength(F::atStep6(PhaseType::FatLoss));

    expect($fatLoss->rateSliderRange())->toEqual(new ClosedRange(0.25, 1.0))
        ->and($fatLoss->rateSliderValue())->toBe(0.75)
        ->and(rateLength(F::atStep6(PhaseType::MuscleGain))->rateSliderRange())->toEqual(new ClosedRange(0.125, 0.5))
        ->and(rateLength(F::atStep6(PhaseType::MuscleGain))->rateSliderValue())->toBe(0.375)
        ->and(rateLength(F::atStep6(PhaseType::Maintenance))->rateSliderRange())->toEqual(new ClosedRange(-0.25, 0.25))
        ->and(rateLength(new Draft)->rateSliderValue())->toBe(0.0);
});

it('ONB-008 a Muscle gain target is above the starting weight', function () {
    expect(rateLength(F::atStep6(PhaseType::MuscleGain))->targetWeightKg())->toEqualWithDelta(83.6, 0.0001);
});

it('ONB-008 a Hold rate leaves no target to edit', function () {
    $hold = rateLength(F::atStep6(PhaseType::Maintenance));

    expect($hold->canEditTarget())->toBeFalse()
        ->and($hold->targetWeightKg())->toEqualWithDelta(80, 0.0001)
        ->and(rateLength(F::atStep6(PhaseType::Maintenance)->with(ratePercentPerWeek: 0.1))->canEditTarget())->toBeTrue()
        ->and(rateLength(F::atStep6()->with(weightKg: null))->canEditTarget())->toBeFalse();
});

it('ONB-008 the target shows in the chosen unit', function () {
    expect(rateLength(F::atStep6()->with(weightUnit: WeightUnit::Lb))->targetWeightValue())
        ->toEqualWithDelta(72.8 / 0.45359237, 0.0001);
});

// ONB-009 limits

it('ONB-009 the length limits follow TBL-010, with a year for Maintenance', function () {
    expect(rateLength(F::atStep6(PhaseType::FatLoss))->weeksRange())->toEqual(new ClosedRange(2, 16))
        ->and(rateLength(F::atStep6(PhaseType::MuscleGain))->weeksRange())->toEqual(new ClosedRange(4, 16))
        ->and(rateLength(F::atStep6(PhaseType::Maintenance))->weeksRange())->toEqual(new ClosedRange(2, 52));
});

it('ONB-009 the recommended range shows under the slider', function () {
    expect(rateLength(F::atStep6(PhaseType::FatLoss))->recommendedRangeText())->toBe('Recommended: 6 to 12 weeks')
        ->and(rateLength(F::atStep6(PhaseType::MuscleGain))->recommendedRangeText())->toBe('Recommended: 8 to 16 weeks')
        ->and(rateLength(F::atStep6(PhaseType::Maintenance))->recommendedRangeText())->toBeNull();
});

it('ONB-009 a fat-loss phase under six weeks suggests a Mini-cut', function () {
    expect(rateLength(F::atStep6()->with(weeks: 4))->warnings())->toBe(['Under 6 weeks, a Mini-cut may suit you better.'])
        ->and(rateLength(F::atStep6()->with(weeks: 6))->warnings())->toBe([]);
});

it('ONB-009 a fat-loss phase over twelve weeks warns', function () {
    expect(rateLength(F::atStep6()->with(ratePercentPerWeek: -0.5, weeks: 13))->warnings())
        ->toBe(['Fat-loss phases over 12 weeks are hard to sustain.'])
        ->and(rateLength(F::atStep6()->with(ratePercentPerWeek: -0.5, weeks: 12))->warnings())->toBe([]);
});

it('ONB-009 planning to lose more than 10% warns', function () {
    expect(rateLength(F::atStep6())->warnings())->toBe([])
        ->and(rateLength(F::atStep6()->with(ratePercentPerWeek: -1.0, customRate: true))->warnings())
        ->toBe(['This plan loses more than 10% of your starting weight. Consider a slower rate or a shorter phase.'])
        ->and(rateLength(F::atStep6()->with(ratePercentPerWeek: -1.0, customRate: true, weeks: 14))->warnings())
        ->toBe([
            'Fat-loss phases over 12 weeks are hard to sustain.',
            'This plan loses more than 10% of your starting weight. Consider a slower rate or a shorter phase.',
        ]);
});

it('ONB-009 exactly 10% does not warn, more does', function () {
    expect(rateLength(F::atStep6()->with(ratePercentPerWeek: -1.0, weeks: 10))->warnings())->toBe([])
        ->and(rateLength(F::atStep6()->with(ratePercentPerWeek: -1.0, weeks: 11))->warnings())
        ->toBe(['This plan loses more than 10% of your starting weight. Consider a slower rate or a shorter phase.']);
});

it('ONB-009 Muscle gain and Maintenance have no fat-loss warnings', function () {
    expect(rateLength(F::atStep6(PhaseType::MuscleGain)->with(weeks: 16))->warnings())->toBe([])
        ->and(rateLength(F::atStep6(PhaseType::Maintenance)->with(weeks: 2))->warnings())->toBe([]);
});
