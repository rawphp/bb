<?php

use App\Domain\Defaults\TBL051ReviewThresholds;
use App\Domain\Model\ClosedRange;
use App\Domain\Model\MuscleGroup;
use App\Domain\Progression\LoggedExercise;
use App\Domain\Progression\LoggedSet;
use App\Domain\Progression\OutOfRepRange;
use App\Domain\Progression\OutOfRepRangeDirection;

/**
 * Bench in the 8 to 12 range at 100 kg with the given reps per set.
 *
 * @param  list<int>  $reps
 */
function outOfRangeBench(array $reps): LoggedExercise
{
    return new LoggedExercise(
        'Bench press', MuscleGroup::Chest, new ClosedRange(8, 12),
        array_map(fn (int $done) => new LoggedSet(100, 10, $done), $reps),
    );
}

it('PROG-053 reps below the range for 2 sessions move the weight down one step', function () {
    $low = outOfRangeBench([6, 7, 9]);
    $adjustment = OutOfRepRange::adjustment([$low, $low], nextTargetWeight: 102.5, step: 2.5);

    expect($adjustment?->direction)->toBe(OutOfRepRangeDirection::Below)
        ->and($adjustment?->weight)->toBe(100.0)
        ->and($adjustment?->note)->toBe('Your reps were below 8 to 12 on most sets for 2 sessions, so the weight goes down one step.');
});

it('PROG-053 reps above the range for 2 sessions move the weight up one step', function () {
    $high = outOfRangeBench([14, 13, 11]);
    $adjustment = OutOfRepRange::adjustment([$high, $high], nextTargetWeight: 100, step: 2);

    expect($adjustment?->direction)->toBe(OutOfRepRangeDirection::Above)
        ->and($adjustment?->weight)->toBe(102.0)
        ->and($adjustment?->note)->toBe('Your reps were above 8 to 12 on most sets for 2 sessions, so the weight goes up one step.');
});

it('PROG-053 uses the TBL-051 session count', function () {
    expect(TBL051ReviewThresholds::OUT_OF_REP_RANGE_SESSIONS_RUNNING)->toBe(2);
    $low = outOfRangeBench([6, 6]);

    expect(OutOfRepRange::adjustment([$low], 100, 2.5))->toBeNull()
        // Only the latest sessions count.
        ->and(OutOfRepRange::adjustment([$low, $low, outOfRangeBench([10, 10])], 100, 2.5))->toBeNull()
        ->and(OutOfRepRange::adjustment([outOfRangeBench([10, 10]), $low, $low], 100, 2.5))->not->toBeNull();
});

it('PROG-053 needs most sets outside the range in the same direction', function () {
    expect(OutOfRepRange::direction(outOfRangeBench([7, 7, 10, 10])))->toBeNull()
        ->and(OutOfRepRange::direction(outOfRangeBench([7, 7, 7, 10])))->toBe(OutOfRepRangeDirection::Below)
        ->and(OutOfRepRange::direction(outOfRangeBench([8, 12, 10])))->toBeNull()
        ->and(OutOfRepRange::adjustment([outOfRangeBench([6, 6]), outOfRangeBench([14, 14])], 100, 2.5))->toBeNull();
});

it('PROG-053 never goes below zero and skips exercises with no step', function () {
    $low = outOfRangeBench([5, 5]);
    expect(OutOfRepRange::adjustment([$low, $low], 1, 2.5)?->weight)->toBe(0.0)
        ->and(OutOfRepRange::adjustment([$low, $low], 0, 2.5))->toBeNull()
        ->and(OutOfRepRange::adjustment([$low, $low], 100, 0))->toBeNull();
});
