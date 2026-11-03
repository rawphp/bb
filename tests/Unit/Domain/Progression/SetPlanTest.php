<?php

use App\Domain\Defaults\TBL040LoadSteps\LoadType;
use App\Domain\Model\ClosedRange;
use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\WeightUnit;
use App\Domain\Progression\DeloadHalf;
use App\Domain\Progression\LoadRules;
use App\Domain\Progression\PlannedLoad;
use App\Domain\Progression\SetLoad;
use App\Domain\Progression\SetPlan;
use App\Domain\Progression\SetPlanInputs;

/**
 * @param  list<SetLoad>  $lastWeek
 * @return list<PlannedLoad>
 */
function setPlanTargets(int $sets = 3, array $lastWeek = [], ?SetLoad $carried = null, ?int $rir = 2, ?DeloadHalf $deload = null, bool $rough = false): array
{
    $rules = new LoadRules(LoadType::BarbellOrSmithMachine, WeightUnit::Kg, false, 2.5, new ClosedRange(6, 30));

    return SetPlan::targets(new SetPlanInputs(
        sets: $sets, lastWeek: $lastWeek, carried: $carried, rir: $rir, deloadHalf: $deload, isFatLoss: false,
        experience: ExperienceLevel::Intermediate, rules: $rules, lastWeekWasRough: $rough,
    ));
}

it('WO-006 with nothing to build on the sets are empty', function () {
    expect(setPlanTargets())->toEqual(array_fill(0, 3, new PlannedLoad(null, null)))
        ->and(setPlanTargets(sets: -1))->toBe([]);
});

it('PROG-020 last week\'s sets set this week\'s', function () {
    $lastWeek = [new SetLoad(100, 10), new SetLoad(100, 8)];
    expect(setPlanTargets(sets: 2, lastWeek: $lastWeek))->toEqual([new PlannedLoad(102.5, 10), new PlannedLoad(102.5, 8)]);
});

it('PROG-026 an added set drops reps like the sets before it', function () {
    expect(setPlanTargets(sets: 3, lastWeek: [new SetLoad(100, 10)]))
        ->toEqual([new PlannedLoad(102.5, 10), new PlannedLoad(102.5, 8), new PlannedLoad(102.5, 6)]);
});

it('PROG-041 the first half of a deload keeps the weight at half the reps', function () {
    $lastWeek = [new SetLoad(82.5, 9), new SetLoad(80, 1)];
    expect(setPlanTargets(sets: 3, lastWeek: $lastWeek, rir: null, deload: DeloadHalf::First))
        ->toEqual([new PlannedLoad(82.5, 4), new PlannedLoad(80, 1), new PlannedLoad(80, 1)]);
});

it('PROG-041 the second half halves the weight to the nearest step', function () {
    expect(setPlanTargets(sets: 1, lastWeek: [new SetLoad(82.5, 8)], rir: null, deload: DeloadHalf::Second))
        ->toEqual([new PlannedLoad(42.5, 4)]);
});

it('PROG-041 a deload with no last week is empty', function () {
    expect(setPlanTargets(sets: 2, rir: null, deload: DeloadHalf::First))
        ->toEqual([new PlannedLoad(null, null), new PlannedLoad(null, null)]);
});

it('MESO-061 a copied mesocycle starts from the old week 1', function () {
    expect(setPlanTargets(sets: 2, carried: new SetLoad(60, 12)))
        ->toEqual([new PlannedLoad(62.5, 12), new PlannedLoad(62.5, 10)]);
});

it('SCH-063 after a rough week the target weight does not rise', function () {
    $lastWeek = [new SetLoad(100, 10), new SetLoad(100, 8)];

    expect(setPlanTargets(sets: 2, lastWeek: $lastWeek, rough: true))->toEqual([new PlannedLoad(100, 10), new PlannedLoad(100, 8)]);
});

it('SCH-063 after a rough week no rep is added where rounding holds the weight', function () {
    // 2% on 20 kg rounds back to 20 kg on a 2.5 kg step, so a normal week adds a rep (PROG-022).
    expect(setPlanTargets(sets: 1, lastWeek: [new SetLoad(20, 10)]))->toEqual([new PlannedLoad(20, 11)])
        ->and(setPlanTargets(sets: 1, lastWeek: [new SetLoad(20, 10)], rough: true))->toEqual([new PlannedLoad(20, 10)]);
});

it('SCH-063 after a rough week there is no step up past the top of the range', function () {
    // 30 reps is the top of the range: a normal week steps the weight up and goes back to the middle (PROG-027).
    expect(setPlanTargets(sets: 1, lastWeek: [new SetLoad(40, 30)]))->not->toEqual([new PlannedLoad(40, 30)])
        ->and(setPlanTargets(sets: 1, lastWeek: [new SetLoad(40, 30)], rough: true))->toEqual([new PlannedLoad(40, 30)]);
});

it('SCH-063 after a rough week a bodyweight-plus-load set logged with no added load repeats at 0 added', function () {
    $rules = new LoadRules(LoadType::BodyweightPlusLoad, WeightUnit::Kg, false, null, new ClosedRange(6, 30));
    $plan = fn (bool $rough) => SetPlan::targets(new SetPlanInputs(
        sets: 2, lastWeek: [new SetLoad(null, 12), new SetLoad(null, 10)], carried: null, rir: 2, deloadHalf: null,
        isFatLoss: false, experience: ExperienceLevel::Intermediate, rules: $rules, lastWeekWasRough: $rough,
    ));

    // toEqual holds null equal to 0.0, so the weights are compared exactly.
    expect(array_map(fn (PlannedLoad $load) => [$load->weight, $load->reps], $plan(true)))->toBe([[0.0, 12], [0.0, 10]])
        ->and($plan(false)[0]->weight)->not->toBeNull();
});

it('SCH-063 after a rough week an added set still drops reps like the sets before it', function () {
    expect(setPlanTargets(sets: 3, lastWeek: [new SetLoad(100, 10)], rough: true))
        ->toEqual([new PlannedLoad(100, 10), new PlannedLoad(100, 8), new PlannedLoad(100, 6)]);
});

it('SCH-063 a rough week leaves a deload and an empty last week as they were', function () {
    $lastWeek = [new SetLoad(82.5, 9), new SetLoad(80, 1)];

    expect(setPlanTargets(sets: 3, lastWeek: $lastWeek, rir: null, deload: DeloadHalf::First, rough: true))
        ->toEqual(setPlanTargets(sets: 3, lastWeek: $lastWeek, rir: null, deload: DeloadHalf::First))
        ->and(setPlanTargets(sets: 2, carried: new SetLoad(60, 12), rough: true))->toEqual(setPlanTargets(sets: 2, carried: new SetLoad(60, 12)));
});

it('SCH-063 a week that is not rough progresses exactly as before', function () {
    $lastWeek = [new SetLoad(100, 10), new SetLoad(100, 8)];

    expect(setPlanTargets(sets: 3, lastWeek: $lastWeek, rough: false))
        ->toEqual([new PlannedLoad(102.5, 10), new PlannedLoad(102.5, 8), new PlannedLoad(102.5, 6)]);
});
