<?php

use App\Domain\Model\ClosedRange;
use App\Domain\Model\MuscleGroup;
use App\Domain\Progression\LoggedExercise;
use App\Domain\Progression\LoggedSet;
use App\Domain\Progression\Underperformance;

/**
 * A logged exercise whose sets all aimed for $target reps at 100 kg and got $reps.
 *
 * @param  list<int>  $reps
 */
function underperformanceExercise(MuscleGroup $muscle, array $reps, int $target = 10): LoggedExercise
{
    return new LoggedExercise(
        $muscle->title().' lift', $muscle, new ClosedRange(8, 12),
        array_map(fn (int $done) => new LoggedSet(100, $target, $done), $reps),
    );
}

it('PROG-050 flags a muscle that missed by 2 or more on most sets in 2 sessions running', function () {
    $missed = [underperformanceExercise(MuscleGroup::Chest, [8, 7, 10])];
    expect(Underperformance::flaggedMuscles([$missed, $missed]))->toBe([MuscleGroup::Chest]);
});

it('PROG-050 missing by 1 rep is not a miss', function () {
    $close = [underperformanceExercise(MuscleGroup::Chest, [9, 9, 9])];
    expect(Underperformance::missed(MuscleGroup::Chest, $close))->toBeFalse()
        ->and(Underperformance::flaggedMuscles([$close, $close]))->toBe([]);
});

it('PROG-050 half the sets is not most', function () {
    expect(Underperformance::missed(MuscleGroup::Chest, [underperformanceExercise(MuscleGroup::Chest, [8, 8, 10, 10])]))->toBeFalse()
        ->and(Underperformance::missed(MuscleGroup::Chest, [underperformanceExercise(MuscleGroup::Chest, [8, 8, 8, 10])]))->toBeTrue();
});

it('PROG-050 counts every set of the muscle across its exercises', function () {
    $session = [
        underperformanceExercise(MuscleGroup::Back, [7, 7]), underperformanceExercise(MuscleGroup::Back, [10, 10, 10]),
        underperformanceExercise(MuscleGroup::Chest, [5]),
    ];
    expect(Underperformance::missed(MuscleGroup::Back, $session))->toBeFalse();
    $worse = [underperformanceExercise(MuscleGroup::Back, [7, 7]), underperformanceExercise(MuscleGroup::Back, [8, 10, 10])];
    expect(Underperformance::missed(MuscleGroup::Back, $worse))->toBeTrue();
});

it('PROG-050 needs 2 sessions running that trained the muscle', function () {
    $missed = [underperformanceExercise(MuscleGroup::Chest, [7, 7])];
    $fine = [underperformanceExercise(MuscleGroup::Chest, [10, 10])];
    $otherMuscle = [underperformanceExercise(MuscleGroup::Quads, [10, 10])];

    expect(Underperformance::flaggedMuscles([$missed]))->toBe([])
        ->and(Underperformance::flaggedMuscles([$missed, $fine]))->toBe([])
        ->and(Underperformance::flaggedMuscles([$missed, $fine, $missed]))->toBe([])
        // A session that did not train chest does not break the run.
        ->and(Underperformance::flaggedMuscles([$missed, $otherMuscle, $missed]))->toBe([MuscleGroup::Chest])
        // The latest two count, not any two.
        ->and(Underperformance::flaggedMuscles([$missed, $missed, $fine]))->toBe([]);
});

it('PROG-050 flags each muscle on its own', function () {
    $session = [underperformanceExercise(MuscleGroup::Chest, [7, 7]), underperformanceExercise(MuscleGroup::Biceps, [10, 10])];
    expect(Underperformance::flaggedMuscles([$session, $session]))->toBe([MuscleGroup::Chest]);
});

it('PROG-051 recommends holding sets and repeating the weight', function () {
    $advice = Underperformance::advice(MuscleGroup::Chest, sets: 4, lastWeight: 82.5);
    expect($advice->muscle)->toBe(MuscleGroup::Chest)
        ->and($advice->sets)->toBe(4)
        ->and($advice->weight)->toBe(82.5)
        ->and($advice->canIgnore)->toBeTrue()
        ->and($advice->note)->toBe('Chest missed its rep targets 2 sessions running. Hold sets and repeat the weight to recover.');
});
