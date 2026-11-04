<?php

use App\Capabilities\Reads\Workout\WorkoutWords;
use App\Domain\Model\WeightUnit;
use App\Domain\Progression\BestSetChange;
use App\Domain\Progression\SetLoad;

// WO-011: how the recap of a finished workout words its numbers.

it('WO-011 a session\'s length reads in minutes, then hours and minutes', function () {
    expect(array_map(WorkoutWords::duration(...), [0, 48, 60, 72, 135]))->toBe(['0 min', '48 min', '1 h', '1 h 12 min', '2 h 15 min']);
});

it('WO-011 the weight lifted reads as a whole number with thousands marked, in the user\'s unit', function () {
    expect(WorkoutWords::lifted(4171.5, WeightUnit::Kg))->toBe('4,172 kg')
        ->and(WorkoutWords::lifted(950.0, WeightUnit::Lb))->toBe('950 lb');
});

it('WO-011 a best set reads as weight times reps, or reps alone with no weight', function () {
    expect(WorkoutWords::bestSet(new SetLoad(82.5, 10), WeightUnit::Kg))->toBe('82.5 kg × 10')
        ->and(WorkoutWords::bestSet(new SetLoad(null, 12), WeightUnit::Kg))->toBe('12 reps')
        ->and(WorkoutWords::bestSet(null, WeightUnit::Kg))->toBe('No sets logged');
});

it('WO-011 a change on last week reads as the weight or the reps it moved, good only when it went up', function () {
    $words = fn (BestSetChange $change) => (array) WorkoutWords::recapChange($change, WeightUnit::Lb)->toArray();

    expect($words(new BestSetChange(5.0, 0)))->toBe(['text' => '+5 lb on last week', 'tone' => 'good'])
        ->and($words(new BestSetChange(-2.5, 0)))->toBe(['text' => '−2.5 lb on last week', 'tone' => 'neutral'])
        ->and($words(new BestSetChange(0.0, 2)))->toBe(['text' => '+2 reps on last week', 'tone' => 'good'])
        ->and($words(new BestSetChange(0.0, -1)))->toBe(['text' => '−1 rep on last week', 'tone' => 'neutral'])
        ->and($words(new BestSetChange(0.0, 0)))->toBe(['text' => 'Same as last week', 'tone' => 'neutral']);
});
