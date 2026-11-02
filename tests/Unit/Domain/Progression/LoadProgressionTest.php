<?php

use App\Domain\Defaults\TBL040LoadSteps\LoadType;
use App\Domain\Model\ClosedRange;
use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\WeightUnit;
use App\Domain\Progression\LoadProgression;
use App\Domain\Progression\LoadRules;
use App\Domain\Progression\Retarget;
use App\Domain\Progression\RetargetKind;
use App\Domain\Progression\SetLoad;
use App\Domain\Progression\SetTarget;

function loadRules(
    LoadType $type = LoadType::BarbellOrSmithMachine,
    WeightUnit $unit = WeightUnit::Kg,
    bool $microPlates = false,
    ?float $stepOverride = null,
    ?ClosedRange $repRange = null,
    ?float $trend = null,
): LoadRules {
    return new LoadRules($type, $unit, $microPlates, $stepOverride, $repRange ?? new ClosedRange(6, 30), $trend);
}

/** @return list<SetLoad> */
function loadSets(?float $weight, int ...$reps): array
{
    return array_map(fn (int $done) => new SetLoad($weight, $done), array_values($reps));
}

/**
 * @param  list<SetTarget>  $targets
 * @return list<mixed>
 */
function loadPluck(array $targets, string $property): array
{
    return array_map(fn (SetTarget $target) => $target->{$property}, $targets);
}

it('PROG-020 each set gets weight and reps from last week', function () {
    $result = LoadProgression::targets(loadSets(100, 10, 8, 7), rir: 2, isFatLoss: false, rules: loadRules());
    expect(loadPluck($result, 'weight'))->toBe([102.5, 102.5, 102.5])
        ->and(loadPluck($result, 'reps'))->toBe([10, 8, 7])
        ->and(loadPluck($result, 'rir'))->toBe([2, 2, 2])
        ->and(LoadProgression::targets([], rir: 2, isFatLoss: false, rules: loadRules()))->toBe([]);
});

it('PROG-021 the weight rises 2% rounded to the step', function () {
    $result = LoadProgression::targets(loadSets(100, 10), rir: 2, isFatLoss: false, rules: loadRules());
    expect($result[0]->weight)->toBe(102.5);
});

it('PROG-021 fat loss rises 1%', function () {
    // 1% of 100 is 101, which rounds back to 100 on a 2.5 kg machine step.
    $fatLoss = LoadProgression::targets(loadSets(100, 10), rir: 2, isFatLoss: true, rules: loadRules(LoadType::MachineOrCable));
    expect($fatLoss[0]->weight)->toBe(100.0)
        ->and($fatLoss[0]->reps)->toBe(11);
    // 2% would have moved it.
    $normal = LoadProgression::targets(loadSets(100, 10), rir: 2, isFatLoss: false, rules: loadRules(LoadType::MachineOrCable));
    expect($normal[0]->weight)->toBe(102.5);
});

it('PROG-022 an unchanged weight adds a rep to each set', function () {
    $result = LoadProgression::targets(loadSets(20, 10, 8), rir: 2, isFatLoss: false, rules: loadRules(LoadType::Dumbbell));
    expect(loadPluck($result, 'weight'))->toBe([20.0, 20.0])
        ->and(loadPluck($result, 'reps'))->toBe([11, 9]);
});

it('PROG-023 fat loss uses the smallest step even with micro plates off', function () {
    $fatLoss = loadRules(LoadType::BarbellOrSmithMachine, microPlates: false);
    expect($fatLoss->step(isFatLoss: true))->toBe(1.25)
        ->and($fatLoss->step(isFatLoss: false))->toBe(2.5);
    $result = LoadProgression::targets(loadSets(100, 10), rir: 2, isFatLoss: true, rules: $fatLoss);
    expect($result[0]->weight)->toBe(101.25)
        ->and(loadRules(unit: WeightUnit::Lb)->step(isFatLoss: true))->toBe(2.5);
});

it('PROG-024 entering a different weight recalculates reps', function () {
    $target = new SetTarget(140, 26, 2);
    $lb = loadRules(unit: WeightUnit::Lb);
    expect(LoadProgression::retarget($target, enteredWeight: 145, lastWeekWeight: 140, rules: $lb))->toEqual(Retarget::reps(24))
        ->and(LoadProgression::retarget($target, enteredWeight: 135, lastWeekWeight: 140, rules: $lb))->toEqual(Retarget::reps(28))
        ->and(LoadProgression::retarget($target, enteredWeight: 140, lastWeekWeight: 140, rules: $lb))->toEqual(Retarget::reps(26));
});

it('PROG-025 more than 15% away shows RIR only', function () {
    $target = new SetTarget(100, 10, 2);
    $lb = loadRules(unit: WeightUnit::Lb);
    expect(LoadProgression::retarget($target, 116, 100, $lb))->toEqual(Retarget::rirOnly(2))
        ->and(LoadProgression::retarget($target, 84, 100, $lb))->toEqual(Retarget::rirOnly(2))
        // Exactly 15% away still recalculates reps.
        ->and(LoadProgression::retarget($target, 115, 100, $lb)->kind)->toBe(RetargetKind::Reps)
        // No last week, nothing to compare.
        ->and(LoadProgression::retarget($target, 116, null, $lb)->kind)->toBe(RetargetKind::Reps);
});

it('PROG-026 week 1 drops 2 reps per set', function () {
    $result = LoadProgression::firstWeekTargets(weight: 60, firstSetReps: 12, sets: 4, rir: 3);
    expect(loadPluck($result, 'reps'))->toBe([12, 10, 8, 6])
        ->and(loadPluck($result, 'weight'))->toBe([60.0, 60.0, 60.0, 60.0])
        ->and(loadPluck($result, 'rir'))->toBe([3, 3, 3, 3])
        ->and(loadPluck(LoadProgression::firstWeekTargets(60, 3, 4, 3), 'reps'))->toBe([3, 1, 1, 1]);
});

it('PROG-026 later weeks follow last week\'s drop-off and stay lower', function () {
    $followed = LoadProgression::targets(loadSets(100, 10, 9, 5), rir: 2, isFatLoss: false, rules: loadRules());
    expect(loadPluck($followed, 'reps'))->toBe([10, 9, 5]);
    $flat = LoadProgression::targets(loadSets(100, 8, 8, 8), rir: 2, isFatLoss: false, rules: loadRules());
    expect(loadPluck($flat, 'reps'))->toBe([8, 7, 6]);
});

it('PROG-027 reps past the top move the weight up a step and reset to the middle', function () {
    $result = LoadProgression::targets(
        loadSets(20, 12, 10), rir: 1, isFatLoss: false, rules: loadRules(LoadType::Dumbbell, repRange: new ClosedRange(6, 12)),
    );
    expect(loadPluck($result, 'weight'))->toBe([22.0, 22.0])
        ->and(loadPluck($result, 'reps'))->toBe([9, 7]);
});

it('PROG-027 the step up keeps a rise larger than one step', function () {
    // 2% of 200 is 204, which rounds to 205: more than one 2.5 kg step above last week.
    $result = LoadProgression::targets(
        loadSets(200, 13, 11), rir: 1, isFatLoss: false, rules: loadRules(repRange: new ClosedRange(6, 12)),
    );
    expect(loadPluck($result, 'weight'))->toBe([205.0, 205.0])
        ->and(loadPluck($result, 'reps'))->toBe([9, 7]);
});

it('PROG-027 bodyweight reps are capped at the top', function () {
    $result = LoadProgression::targets(
        loadSets(null, 12, 10), rir: 1, isFatLoss: false, rules: loadRules(LoadType::Bodyweight, repRange: new ClosedRange(6, 12)),
    );
    expect(loadPluck($result, 'weight'))->toBe([null, null])
        ->and(loadPluck($result, 'reps'))->toBe([12, 10]);
});

it('TBL-040 and EX-012 resolve the step', function () {
    expect(loadRules(LoadType::BarbellOrSmithMachine)->step(false))->toBe(2.5)
        ->and(loadRules(LoadType::BarbellOrSmithMachine, unit: WeightUnit::Lb)->step(false))->toBe(5.0)
        ->and(loadRules(LoadType::BarbellOrSmithMachine, microPlates: true)->step(false))->toBe(1.25)
        ->and(loadRules(LoadType::BarbellOrSmithMachine, unit: WeightUnit::Lb, microPlates: true)->step(false))->toBe(2.5)
        ->and(loadRules(LoadType::Dumbbell, microPlates: true)->step(true))->toBe(2.0)
        ->and(loadRules(LoadType::MachineOrCable)->step(false))->toBe(2.5)
        ->and(loadRules(LoadType::BodyweightPlusLoad)->step(false))->toBe(1.25)
        ->and(loadRules(LoadType::Bodyweight)->step(false))->toBeNull()
        ->and(loadRules(LoadType::Dumbbell, stepOverride: 1)->step(true))->toBe(1.0)
        ->and(loadRules(LoadType::Bodyweight, stepOverride: 1)->step(false))->toBeNull();
});

it('EX-012 a step override beats micro plates and fat loss', function () {
    $overridden = loadRules(LoadType::BarbellOrSmithMachine, microPlates: true, stepOverride: 5);
    expect($overridden->step(isFatLoss: true))->toBe(5.0)
        ->and($overridden->step(isFatLoss: false))->toBe(5.0);
});

it('EX-006 bodyweight plus load uses the trend weight as its base', function () {
    $withTrend = loadRules(LoadType::BodyweightPlusLoad, trend: 80);
    $result = LoadProgression::targets(loadSets(20, 10), rir: 2, isFatLoss: false, rules: $withTrend);
    expect($result[0]->weight)->toBe(22.5)
        ->and($result[0]->reps)->toBe(10);

    // Entering 25 added load: total 105 against a planned total of 100.
    $target = new SetTarget(20, 10, 2);
    expect(LoadProgression::retarget($target, enteredWeight: 25, lastWeekWeight: 20, rules: $withTrend))->toEqual(Retarget::reps(8));
});

it('EX-006 bodyweight plus load with no added load still steps', function () {
    // No added load logged: 80 x 1.02 is 1.6 above trend, which rounds to one 1.25 kg step.
    $withTrend = loadRules(LoadType::BodyweightPlusLoad, repRange: new ClosedRange(5, 10), trend: 80);
    $stepped = LoadProgression::targets(loadSets(null, 8, 6), rir: 2, isFatLoss: false, rules: $withTrend);
    expect(loadPluck($stepped, 'weight'))->toBe([1.25, 1.25])
        ->and(loadPluck($stepped, 'reps'))->toBe([8, 6]);

    // Reps past the top still add a step and reset to the middle of the range.
    $noTrend = loadRules(LoadType::BodyweightPlusLoad, repRange: new ClosedRange(5, 10));
    $past = LoadProgression::targets(loadSets(null, 10, 8), rir: 2, isFatLoss: false, rules: $noTrend);
    expect(loadPluck($past, 'weight'))->toBe([1.25, 1.25])
        ->and(loadPluck($past, 'reps'))->toBe([7, 5]);
});

it('EX-006 a bodyweight exercise chases reps from the trend weight', function () {
    $result = LoadProgression::targets(loadSets(null, 10), rir: 2, isFatLoss: false, rules: loadRules(LoadType::Bodyweight, trend: 80));
    expect($result[0]->weight)->toBeNull()
        ->and($result[0]->reps)->toBe(11);
});

it('MESO-061 a copied mesocycle starts 5% above the old week 1', function () {
    $old = new SetLoad(100, 8);
    expect(LoadProgression::copiedStart($old, ExperienceLevel::Intermediate, isFatLoss: false, rules: loadRules()))->toEqual(new SetLoad(105, 8))
        ->and(LoadProgression::copiedStart($old, ExperienceLevel::Advanced, isFatLoss: false, rules: loadRules()))->toEqual(new SetLoad(102.5, 8));
});

it('MESO-063 light dumbbells keep the weight and chase reps', function () {
    $start = LoadProgression::copiedStart(new SetLoad(8, 10), ExperienceLevel::Intermediate, isFatLoss: false, rules: loadRules(LoadType::Dumbbell));
    expect($start)->toEqual(new SetLoad(8, 11));
});

it('EX-012 a step override of 0 means reps only, like a bodyweight exercise', function () {
    $zero = loadRules(stepOverride: 0);
    expect($zero->step(isFatLoss: false))->toBeNull()
        ->and(loadRules(stepOverride: -2.5)->step(isFatLoss: true))->toBeNull();

    // PROG-022: the weight cannot move, so it stays and each set adds a rep.
    $result = LoadProgression::targets(loadSets(100, 10, 8), rir: 2, isFatLoss: false, rules: $zero);
    expect(loadPluck($result, 'weight'))->toBe([100.0, 100.0])
        ->and(loadPluck($result, 'reps'))->toBe([11, 9]);

    // PROG-027: with no step to move up, reps are capped at the top.
    $top = LoadProgression::targets(loadSets(100, 12), rir: 2, isFatLoss: false, rules: loadRules(stepOverride: 0, repRange: new ClosedRange(8, 12)));
    expect($top[0]->weight)->toBe(100.0)
        ->and($top[0]->reps)->toBe(12);

    // MESO-061: a copied start keeps the weight and chases reps.
    $copied = LoadProgression::copiedStart(new SetLoad(100, 10), ExperienceLevel::Intermediate, isFatLoss: false, rules: $zero);
    expect($copied->weight)->toBe(100.0)
        ->and($copied->reps)->toBe(11);
});
