<?php

use App\Domain\Model\FeedbackAnswers;
use App\Domain\Model\Pump;
use App\Domain\Model\Session;
use App\Domain\Model\Soreness;
use App\Domain\Model\VolumeFeedback;
use App\Domain\Model\Workout;
use App\Domain\Progression\ActiveRest;
use App\Domain\Progression\ActiveRestWeek;

/** @param array<string, FeedbackAnswers> $feedback keyed by MuscleGroup value */
function activeRestWorkout(array $feedback): Workout
{
    return new Workout('w', new DateTimeImmutable('@0'), new Session('day-1', 'Day 1', []), $feedback);
}

function activeRestFlat(): FeedbackAnswers
{
    return new FeedbackAnswers(soreness: Soreness::NeverGotSore, pump: Pump::Low, volume: VolumeFeedback::JustRight);
}

function activeRestWorking(): FeedbackAnswers
{
    return new FeedbackAnswers(soreness: Soreness::HealedJustOnTime, pump: Pump::Moderate, volume: VolumeFeedback::JustRight);
}

/**
 * A week where chest, back and quads were flat and biceps was not.
 *
 * @return list<Workout>
 */
function activeRestFlatWeek(): array
{
    return [
        activeRestWorkout(['chest' => activeRestFlat(), 'back' => activeRestFlat()]),
        activeRestWorkout(['quads' => activeRestFlat(), 'biceps' => activeRestWorking()]),
    ];
}

/** @return list<Workout> */
function activeRestNormalWeek(): array
{
    return [
        activeRestWorkout(['chest' => activeRestWorking(), 'back' => activeRestFlat()]),
        activeRestWorkout(['quads' => activeRestWorking(), 'biceps' => activeRestWorking()]),
    ];
}

it('PROG-054 a week is flat when most muscles had a low pump and no soreness', function () {
    expect(ActiveRest::isFlat(activeRestFlatWeek()))->toBeTrue()
        ->and(ActiveRest::isFlat(activeRestNormalWeek()))->toBeFalse()
        // Two of four is not most.
        ->and(ActiveRest::isFlat([activeRestWorkout([
            'chest' => activeRestFlat(), 'back' => activeRestFlat(), 'quads' => activeRestWorking(), 'biceps' => activeRestWorking(),
        ])]))->toBeFalse()
        ->and(ActiveRest::isFlat([]))->toBeFalse();
});

it('PROG-054 a muscle is only flat when both pump and soreness are near zero', function () {
    $lowPumpButSore = new FeedbackAnswers(soreness: Soreness::HealedAWhileAgo, pump: Pump::Low);
    $noSorenessButPump = new FeedbackAnswers(soreness: Soreness::NeverGotSore, pump: Pump::Moderate);

    expect(ActiveRest::isFlat([activeRestWorkout(['chest' => $lowPumpButSore])]))->toBeFalse()
        ->and(ActiveRest::isFlat([activeRestWorkout(['chest' => $noSorenessButPump])]))->toBeFalse()
        ->and(ActiveRest::isFlat([activeRestWorkout(['chest' => activeRestFlat()])]))->toBeTrue();
});

it('PROG-054 every sheet for a muscle that week must be flat', function () {
    expect(ActiveRest::isFlat([
        activeRestWorkout(['chest' => activeRestFlat()]), activeRestWorkout(['chest' => activeRestWorking()]),
    ]))->toBeFalse();
});

it('PROG-054 a muscle with no soreness answer that week is not reported flat', function () {
    $pumpOnly = new FeedbackAnswers(pump: Pump::Low);
    expect(ActiveRest::isFlat([activeRestWorkout(['chest' => $pumpOnly])]))->toBeFalse()
        // A later sheet that asked soreness is enough.
        ->and(ActiveRest::isFlat([activeRestWorkout(['chest' => $pumpOnly]), activeRestWorkout(['chest' => activeRestFlat()])]))->toBeTrue();
});

it('PROG-054 a sheet with no pump answer is ignored', function () {
    $noPump = new FeedbackAnswers(soreness: Soreness::HealedJustOnTime, volume: VolumeFeedback::TooMuch);
    // Two of three muscles with a pump answer were flat; counting the other two would make it two of five.
    $week = [
        activeRestWorkout(['chest' => activeRestFlat(), 'back' => activeRestFlat(), 'quads' => activeRestWorking()]),
        activeRestWorkout(['biceps' => $noPump, 'triceps' => $noPump]),
    ];
    expect(ActiveRest::isFlat($week))->toBeTrue()
        ->and(ActiveRest::isFlat([activeRestWorkout(['chest' => $noPump])]))->toBeFalse();
});

it('PROG-054 recommends active rest after 2 flat weeks running', function () {
    expect(ActiveRest::isRecommended([activeRestFlatWeek()]))->toBeFalse()
        ->and(ActiveRest::isRecommended([activeRestNormalWeek(), activeRestFlatWeek(), activeRestFlatWeek()]))->toBeTrue()
        ->and(ActiveRest::isRecommended([activeRestFlatWeek(), activeRestNormalWeek(), activeRestFlatWeek()]))->toBeFalse();
});

it('MESO-064 a mesocycle with 2 flat weeks running ends in an active rest', function () {
    // Training weeks, then the deload with no feedback (FB: none in deloads).
    $mesocycle = [activeRestNormalWeek(), activeRestFlatWeek(), activeRestFlatWeek(), activeRestNormalWeek(), []];
    expect(ActiveRest::isRecommended($mesocycle))->toBeTrue()
        ->and(ActiveRest::PLAN)->toBe([ActiveRestWeek::DeloadWeek, ActiveRestWeek::WeekOff])
        ->and(ActiveRest::isRecommended([activeRestNormalWeek(), activeRestFlatWeek(), activeRestNormalWeek(), activeRestFlatWeek(), []]))->toBeFalse();
});
