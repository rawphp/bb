<?php

// The same tests as rp-clone's StepPlanCheckTests.swift (pin 2aa1edeb), one for one.

use App\Domain\Model\StepPlanDay;
use App\Domain\Review\StepPlanCheck;
use App\Domain\Review\StepPlanOffer;
use Tests\Unit\Domain\Progress\ProgressFixtures;

it('REV-050 offers when the average differs by more than 2000', function () {
    $offer = StepPlanCheck::offer([8_000, 8_000, 8_000], [10_001, 10_001]);
    expect($offer)->toEqual(new StepPlanOffer(actualAverage: 8_000, plannedAverage: 10_001));
});

it('REV-050 offers when actual is far above the plan', function () {
    $offer = StepPlanCheck::offer([13_000, 12_000], [10_000]);
    expect($offer)->toEqual(new StepPlanOffer(actualAverage: 12_500, plannedAverage: 10_000));
});

it('REV-050 exactly 2000 apart is no offer', function () {
    expect(StepPlanCheck::offer([8_000], [10_000]))->toBeNull()
        ->and(StepPlanCheck::offer([12_000], [10_000]))->toBeNull();
});

it('REV-050 within 2000 is no offer', function () {
    expect(StepPlanCheck::offer([9_500, 10_500], [10_000]))->toBeNull();
});

it('REV-050 no step data is no offer', function () {
    expect(StepPlanCheck::offer([], [10_000]))->toBeNull()
        ->and(StepPlanCheck::offer([1_000], []))->toBeNull();
});

// from the week's actual steps

/** Mon 5 to Sun 11 October 2026. @return list<DateTimeImmutable> */
function stepPlanReviewWeek(): array
{
    return array_map(fn (int $i) => ProgressFixtures::day('2026-10-05', $i), range(0, 6));
}

/** The plan is 6 to 8k (7,000 planned) every day (ISO weekdays 1 to 7). @return list<StepPlanDay> */
function stepPlanEveryDay(): array
{
    return array_map(fn (int $d) => new StepPlanDay(weekday: $d, min: 6_000, max: 8_000), range(1, 7));
}

/**
 * @param  array<int, int>  $steps  keyed by the start of the day (Unix time)
 * @param  list<StepPlanDay>|null  $plan
 */
function stepPlanOffer(array $steps, ?array $plan = null): ?StepPlanOffer
{
    return StepPlanCheck::offerForWeek($steps, stepPlanReviewWeek(), $plan ?? stepPlanEveryDay());
}

it('REV-050 offers to update the plan when health steps differ by more than 2000', function () {
    $steps = [];
    foreach (array_slice(stepPlanReviewWeek(), 0, 5) as $day) {
        $steps[$day->getTimestamp()] = 10_000;
    }
    expect(stepPlanOffer($steps))->toEqual(new StepPlanOffer(actualAverage: 10_000, plannedAverage: 7_000));
});

it('REV-050 no offer within the difference', function () {
    $steps = [];
    foreach (stepPlanReviewWeek() as $day) {
        $steps[$day->getTimestamp()] = 8_900;
    }
    expect(stepPlanOffer($steps))->toBeNull();
});

it('REV-050 days with no health data are left out', function () {
    // Two days of data, both 3,000 under a plan of 7,000; the five empty days do not drag the average to zero
    // or count in the plan.
    $week = stepPlanReviewWeek();
    $steps = [$week[0]->getTimestamp() => 4_000, $week[1]->getTimestamp() => 4_000];
    expect(stepPlanOffer($steps))->toEqual(new StepPlanOffer(actualAverage: 4_000, plannedAverage: 7_000));
});

it('REV-050 no offer without any step data', function () {
    expect(stepPlanOffer([]))->toBeNull();
});

it('REV-050 planned steps follow each day\'s own range', function () {
    $uneven = [new StepPlanDay(weekday: 1, min: 3_000, max: 5_000), new StepPlanDay(weekday: 7, min: 10_000, max: 14_000)];
    $week = stepPlanReviewWeek();
    // Monday plans 4,000 and Sunday 12,000; actual average 7,000 against planned 8,000: within 2,000.
    expect(stepPlanOffer([$week[0]->getTimestamp() => 4_000, $week[6]->getTimestamp() => 10_000], $uneven))->toBeNull();
});
