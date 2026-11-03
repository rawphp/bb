<?php

use App\Domain\Model\ClosedRange;
use App\Domain\Model\PhaseType;
use App\Domain\Onboarding\BusyPeriodChoice;
use App\Domain\Onboarding\Draft;
use App\Domain\Onboarding\OnboardingLifestyle;
use App\Domain\Onboarding\StepDayGroup;
use App\Domain\Onboarding\StepPreset;
use App\Domain\Onboarding\StepRangeChoice;
use App\Domain\Onboarding\WeighInChoice;
use App\Domain\Safety\SafetyWarning;
use Tests\Unit\Domain\Onboarding\OnboardingFixtures as F;

// Steps 10 to 12 (ONB-013 to ONB-016, SAFE-015), the read cases of rp-clone's OnboardingLifestyleTests. Setting a
// range, a window, a busy period or a weigh-in is #67's write; here the draft already holds it.

function onboardingLifestyle(Draft $draft): OnboardingLifestyle
{
    return new OnboardingLifestyle($draft);
}

/**
 * @param  list<int>  $weekdays
 * @return list<StepRangeChoice>
 */
function onboardingStepRanges(array $weekdays, int $min, int $max): array
{
    return array_map(fn (int $weekday) => new StepRangeChoice($weekday, $min, $max), $weekdays);
}

/** @return list<array{int, int}> */
function onboardingRangePairs(OnboardingLifestyle $lifestyle): array
{
    return array_map(fn (StepRangeChoice $range) => [$range->min, $range->max], $lifestyle->stepRanges());
}

// ONB-013 steps

it('ONB-013 every weekday starts with a range: 5k to 8k on weekdays, 8k to 10k at the weekend', function () {
    $lifestyle = onboardingLifestyle(F::atStep10());

    expect(array_map(fn (StepRangeChoice $range) => $range->weekday, $lifestyle->stepRanges()))->toBe([1, 2, 3, 4, 5, 6, 7])
        ->and(onboardingRangePairs($lifestyle))->toBe([
            [5000, 8000], [5000, 8000], [5000, 8000], [5000, 8000], [5000, 8000], [8000, 10000], [8000, 10000],
        ])
        ->and(OnboardingLifestyle::defaultStepRange(6))->toEqual(new StepRangeChoice(6, 8000, 10000));
});

it('ONB-013 weekday and weekend presets read as one range per group', function () {
    $lifestyle = onboardingLifestyle(F::atStep10()->with(stepRanges: [
        ...onboardingStepRanges([1, 2, 3, 4, 5], 0, 8000), ...onboardingStepRanges([6, 7], 8000, 13000),
    ]));

    expect($lifestyle->groupRange(StepDayGroup::Weekdays))->toEqual(new ClosedRange(0, 8000))
        ->and($lifestyle->groupRange(StepDayGroup::Weekends))->toEqual(new ClosedRange(8000, 13000))
        ->and(StepDayGroup::Weekdays->weekdays())->toBe([1, 2, 3, 4, 5])
        ->and(StepDayGroup::Weekends->weekdays())->toBe([6, 7])
        ->and(StepDayGroup::Weekends->title())->toBe('Weekends');
});

it('ONB-013 the presets include the spec examples', function () {
    $presets = OnboardingLifestyle::stepPresets();

    expect(array_map(fn (StepPreset $p) => [$p->min, $p->max], $presets))->toContain([0, 8000], [8000, 13000])
        ->and(array_map(fn (StepPreset $p) => $p->title(), $presets))
        ->toBe(['0 to 5k', '0 to 8k', '5k to 8k', '8k to 10k', '10k to 12k', '8k to 13k']);
});

it('ONB-013 a single day can have its own range, and its group then has none', function () {
    $lifestyle = onboardingLifestyle(F::atStep10()->with(stepRanges: [new StepRangeChoice(3, 2000, 6000)]));

    expect($lifestyle->stepRange(3))->toEqual(new StepRangeChoice(3, 2000, 6000))
        ->and($lifestyle->stepRange(2))->toEqual(new StepRangeChoice(2, 5000, 8000))
        ->and($lifestyle->groupRange(StepDayGroup::Weekdays))->toBeNull()
        ->and($lifestyle->groupRange(StepDayGroup::Weekends))->toEqual(new ClosedRange(8000, 10000));
});

it('ONB-013 saved ranges stand in for their days and the defaults fill the rest', function () {
    $lifestyle = onboardingLifestyle(F::atStep10()->with(stepRanges: onboardingStepRanges([6, 7], 0, 5000)));

    expect($lifestyle->stepRange(6))->toEqual(new StepRangeChoice(6, 0, 5000))
        ->and($lifestyle->stepRange(1))->toEqual(new StepRangeChoice(1, 5000, 8000))
        ->and(OnboardingLifestyle::MAXIMUM_DAILY_STEPS)->toBe(40000);
});

it('SAFE-015 a fat-loss step target above 12,000 warns', function () {
    expect(onboardingLifestyle(F::atStep10())->stepWarning())->toBeNull()
        ->and(onboardingLifestyle(F::atStep10()->with(stepRanges: onboardingStepRanges([6, 7], 8000, 13000)))->stepWarning())
        ->toBe(SafetyWarning::highStepTarget()->message())
        ->toContain('hurt your recovery from lifting');
});

it('SAFE-015 one day above 12,000 warns on its own', function () {
    expect(onboardingLifestyle(F::atStep10()->with(stepRanges: [new StepRangeChoice(3, 0, 12001)]))->stepWarning())
        ->toBe(SafetyWarning::highStepTarget()->message());
});

it('SAFE-015 exactly 12,000 does not warn', function () {
    expect(onboardingLifestyle(F::atStep10()->with(stepRanges: onboardingStepRanges([6, 7], 8000, 12000)))->stepWarning())->toBeNull();
});

it('SAFE-015 other goals do not warn', function () {
    foreach ([PhaseType::MuscleGain, PhaseType::Maintenance] as $goal) {
        expect(onboardingLifestyle(F::atStep10($goal)->with(stepRanges: onboardingStepRanges([6, 7], 8000, 20000)))->stepWarning())->toBeNull();
    }
});

// ONB-014 meals

it('ONB-014 the default window from 7 AM to 8 PM holds five meals', function () {
    $lifestyle = onboardingLifestyle(F::atStep11());

    expect($lifestyle->eatingWindow()->start->minuteOfDay)->toBe(7 * 60)
        ->and($lifestyle->eatingWindow()->end->minuteOfDay)->toBe(20 * 60)
        ->and($lifestyle->mealsFit())->toBeTrue()
        ->and(OnboardingLifestyle::MINIMUM_MEALS)->toBe(2)
        ->and(OnboardingLifestyle::MAXIMUM_MEALS)->toBe(6)
        ->and(OnboardingLifestyle::EATING_WINDOW_NOTE)->toBe('This window applies to every day.');
});

it('ONB-014 a window that cannot hold the meals does not fit', function () {
    expect(onboardingLifestyle(F::atStep11()->with(eatingEndMinutes: 9 * 60, mealCount: 6))->mealsFit())->toBeFalse()
        ->and(OnboardingLifestyle::MEALS_DO_NOT_FIT_HINT)
        ->toBe("The eating window can't hold that many meals. Widen it, remove a busy period or have fewer meals.");
});

it('ONB-014 busy periods count against the window', function () {
    $short = F::atStep11()->with(eatingEndMinutes: 12 * 60, mealCount: 3);
    $busy = $short->with(busyPeriods: [new BusyPeriodChoice('b1', 'Busy', 7 * 60, 11 * 60, [1, 2, 3, 4, 5])]);

    expect(onboardingLifestyle($short)->mealsFit())->toBeTrue()
        ->and(onboardingLifestyle($busy)->mealsFit())->toBeFalse()
        ->and(onboardingLifestyle($busy)->busyRanges()[0]->end->minuteOfDay)->toBe(11 * 60);
});

it('ONB-014 a window may cross midnight', function () {
    expect(onboardingLifestyle(F::atStep11()->with(eatingStartMinutes: 22 * 60, eatingEndMinutes: 6 * 60, mealCount: 3))->mealsFit())->toBeTrue();
});

it('ONB-014 an empty window holds nothing', function () {
    expect(onboardingLifestyle(F::atStep11()->with(eatingEndMinutes: 7 * 60))->mealsFit())->toBeFalse();
});

// ONB-015 weigh-ins

it('ONB-015 fewer than two weigh-in days are too few', function () {
    expect(onboardingLifestyle(F::atStep12())->hasEnoughWeighIns())->toBeFalse()
        ->and(onboardingLifestyle(F::atStep12()->with(weighIns: F::weighIns([2])))->hasEnoughWeighIns())->toBeFalse()
        ->and(onboardingLifestyle(F::atStep12()->with(weighIns: F::weighIns([2, 5])))->hasEnoughWeighIns())->toBeTrue()
        ->and(OnboardingLifestyle::TOO_FEW_WEIGH_INS)->toBe('Too few weigh-ins to continue');
});

it('ONB-015 a hint recommends four or more', function () {
    expect(OnboardingLifestyle::WEIGH_IN_HINT)->toContain('4 or more')
        ->and(OnboardingLifestyle::RECOMMENDED_WEIGH_IN_DAYS)->toBe(4)
        ->and(OnboardingLifestyle::MINIMUM_WEIGH_IN_DAYS)->toBe(2);
});

it('ONB-015 help text says the time only sets the reminder, and a new day starts at 7 AM', function () {
    expect(OnboardingLifestyle::WEIGH_IN_TIME_NOTE)->toContain('only sets your reminder')
        ->and(OnboardingLifestyle::DEFAULT_WEIGH_IN_MINUTES)->toBe(7 * 60);
});

// ONB-016 review day

it('ONB-016 the review day defaults to the day after the last weigh-in day', function () {
    $lifestyle = onboardingLifestyle(F::atStep12()->with(weighIns: F::weighIns([1, 3, 5])));

    expect($lifestyle->defaultReviewWeekday())->toBe(6)
        ->and($lifestyle->reviewWeekday())->toBe(6);
});

it('ONB-016 a Sunday weigh-in wraps to Monday', function () {
    expect(onboardingLifestyle(F::atStep12()->with(weighIns: F::weighIns([2, 7])))->reviewWeekday())->toBe(1);
});

it('ONB-016 a picked review day stays whatever the weigh-ins', function () {
    $picked = F::atStep12()->with(weighIns: F::weighIns([1, 2, 6]), reviewWeekday: 7);

    expect(onboardingLifestyle($picked)->reviewWeekday())->toBe(7)
        ->and(onboardingLifestyle($picked)->defaultReviewWeekday())->toBe(7)
        ->and(onboardingLifestyle($picked->with(reviewWeekday: 4))->reviewWeekday())->toBe(4);
});

it('ONB-016 with no weigh-ins the review day is Monday', function () {
    expect(onboardingLifestyle(F::atStep12())->reviewWeekday())->toBe(1)
        ->and(onboardingLifestyle(F::atStep12()->with(weighIns: [new WeighInChoice(4, 420)]))->reviewWeekday())->toBe(5);
});
