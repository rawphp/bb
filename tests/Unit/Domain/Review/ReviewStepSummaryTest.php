<?php

// The same tests as rp-clone's ReviewStepSummaryTests.swift (pin 2aa1edeb), one for one.

use App\Domain\Model\JointPain;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\Phase;
use App\Domain\Model\PhaseType;
use App\Domain\Model\PlanWeek;
use App\Domain\Review\JointPainFlag;
use App\Domain\Review\NextWeekKind;
use App\Domain\Review\ReviewMealItem;
use App\Domain\Review\ReviewMealsSummary;
use App\Domain\Review\ReviewProgress\Position;
use App\Domain\Review\ReviewProgressRules;
use App\Domain\Review\ReviewTraining;
use App\Domain\Review\ReviewTrainingFacts;
use App\Domain\Review\ReviewWeighInRow;
use App\Domain\Review\ReviewWeighIns;
use App\Domain\Weight\TrendWeight;
use Tests\Unit\Domain\Plan\PlanFixtures;
use Tests\Unit\Domain\Review\ReviewStepSummaryFixtures as F;

// step 1, weigh-ins

it('REV-003 weigh-ins list each scheduled day and flag the missing ones', function () {
    // Scheduled on Monday and Thursday (ISO 1 and 4); only Monday was weighed.
    $rows = ReviewWeighIns::rows(new PlanWeek(F::day(5)), [1, 4], [F::weighIn(5, kg: 82.4)]);
    expect(array_map(fn (ReviewWeighInRow $row) => $row->day, $rows))->toEqual([F::day(5), F::day(8)])
        ->and(array_map(fn (ReviewWeighInRow $row) => $row->weightKg, $rows))->toBe([82.4, null])
        ->and(array_map(fn (ReviewWeighInRow $row) => $row->isMissing, $rows))->toBe([false, true]);
});

it('REV-003 an unscheduled weigh-in is listed too', function () {
    $rows = ReviewWeighIns::rows(new PlanWeek(F::day(5)), [1], [F::weighIn(5), F::weighIn(10, kg: 81.9)]);
    expect(array_map(fn (ReviewWeighInRow $row) => $row->day, $rows))->toEqual([F::day(5), F::day(10)])
        ->and(array_map(fn (ReviewWeighInRow $row) => $row->isScheduled, $rows))->toBe([true, false])
        ->and(array_map(fn (ReviewWeighInRow $row) => $row->isMissing, $rows))->toBe([false, false]);
});

it("REV-003 a day's earliest reading is the one shown and outside readings are left out", function () {
    $rows = ReviewWeighIns::rows(new PlanWeek(F::day(5)), [1], [
        F::weighIn(5, hour: 18, kg: 83), F::weighIn(5, hour: 6, kg: 82.2), F::weighIn(4, kg: 90), F::weighIn(12, kg: 90),
    ]);
    expect(array_map(fn (ReviewWeighInRow $row) => $row->weightKg, $rows))->toBe([82.2]);
});

it('REV-003 an away week has no missing weigh-ins', function () {
    // SCH-061: weigh-ins are optional in an away week.
    $rows = ReviewWeighIns::rows(new PlanWeek(F::day(5), isAway: true), [1, 4], []);
    expect($rows)->not->toBeEmpty();
    foreach ($rows as $row) {
        expect($row->isMissing)->toBeFalse();
    }
});

// step 2, meals

function reviewMeal(string $id, int $dayNumber, bool $checked): ReviewMealItem
{
    return new ReviewMealItem($id, F::day($dayNumber), "Meal {$id}", $checked);
}

it('REV-003 checking in every meal is excellent work', function () {
    $summary = new ReviewMealsSummary([reviewMeal('1', 5, true), reviewMeal('2', 5, true)]);
    expect($summary->isExcellent())->toBeTrue()
        ->and($summary->message())->toBe('Excellent work')
        ->and($summary->unchecked())->toBeEmpty();
});

it('REV-003 unchecked meals are listed and counted', function () {
    $summary = new ReviewMealsSummary([
        reviewMeal('1', 5, true), reviewMeal('2', 6, false), reviewMeal('3', 7, false),
    ]);
    expect($summary->isExcellent())->toBeFalse()
        ->and($summary->total())->toBe(3)
        ->and($summary->checkedIn())->toBe(1)
        ->and(array_map(fn (ReviewMealItem $meal) => $meal->id, $summary->unchecked()))->toBe(['2', '3'])
        ->and($summary->message())->toBe('1 of 3 meals checked in');
});

it('REV-003 a week with no meals is not praised', function () {
    expect((new ReviewMealsSummary([]))->isExcellent())->toBeFalse()
        ->and((new ReviewMealsSummary([]))->message())->toBe('No meals were planned this week');
});

// step 3, training

it('REV-003 sessions done, skipped and missed add up to the plan', function () {
    $summary = ReviewTraining::summary(
        new ReviewTrainingFacts(
            plannedSessions: 4, plannedSets: 60, plannedSetsByMuscle: [], doneSessions: 2, skippedSessions: 1,
            loggedSets: 38,
        ),
        [], [], [], NextWeekKind::Normal,
    );
    expect($summary->sessionsDone)->toBe(2)
        ->and($summary->sessionsSkipped)->toBe(1)
        ->and($summary->sessionsMissed)->toBe(1)
        ->and($summary->setsDone)->toBe(38)
        ->and($summary->setsPlanned)->toBe(60);
});

it('REV-003 missed sessions never go below zero', function () {
    $summary = ReviewTraining::summary(
        new ReviewTrainingFacts(
            plannedSessions: 3, plannedSets: 40, plannedSetsByMuscle: [], doneSessions: 4, skippedSessions: 0,
            loggedSets: 50,
        ),
        [], [], [], NextWeekKind::Normal,
    );
    expect($summary->sessionsMissed)->toBe(0);
});

it('REV-003 underperforming muscles are listed by name', function () {
    $summary = ReviewTraining::summary(
        ReviewTrainingFacts::empty(), [MuscleGroup::Quads, MuscleGroup::Back], [], [], NextWeekKind::Normal,
    );
    expect($summary->underperformingMuscles)->toBe([MuscleGroup::Back, MuscleGroup::Quads]);
});

it('REV-003 a muscle at its volume ceiling is flagged', function () {
    $facts = new ReviewTrainingFacts(
        plannedSessions: 4, plannedSets: 40,
        plannedSetsByMuscle: [MuscleGroup::Chest->value => 22, MuscleGroup::Back->value => 10, MuscleGroup::Biceps->value => 0],
        doneSessions: 4, skippedSessions: 0, loggedSets: 40,
    );
    $summary = ReviewTraining::summary(
        $facts, [],
        [MuscleGroup::Chest->value => 22, MuscleGroup::Back->value => 25, MuscleGroup::Biceps->value => 0],
        [], NextWeekKind::Normal,
    );
    expect($summary->musclesAtCeiling)->toBe([MuscleGroup::Chest], 'a muscle with no sets, or a ceiling of 0, is not at it');
});

it('REV-003 joint pain keeps the worst answer per exercise and leaves none out', function () {
    $flags = ReviewTraining::jointPainFlags([
        ['exerciseName' => 'Bench Press', 'level' => JointPain::Low],
        ['exerciseName' => 'Bench Press', 'level' => JointPain::Moderate],
        ['exerciseName' => 'Squat', 'level' => JointPain::None],
        ['exerciseName' => 'Curl', 'level' => JointPain::ALot],
    ]);
    expect($flags)->toEqual([
        new JointPainFlag('Bench Press', JointPain::Moderate), new JointPainFlag('Curl', JointPain::ALot),
    ]);
});

it('REV-003 next week is normal, the last hard week or a deload', function () {
    // A 5-week mesocycle: week 5 is the deload.
    expect(ReviewTraining::nextWeekKind(2, 5, false))->toBe(NextWeekKind::Normal)
        ->and(ReviewTraining::nextWeekKind(4, 5, false))->toBe(NextWeekKind::LastHardWeek)
        ->and(ReviewTraining::nextWeekKind(5, 5, false))->toBe(NextWeekKind::Deload)
        ->and(ReviewTraining::nextWeekKind(2, 5, true))->toBe(NextWeekKind::Deload, 'a flagged deload week')
        ->and(ReviewTraining::nextWeekKind(null, null, false))->toBe(NextWeekKind::Normal);
});

// step 4, progress

/** 82 kg on every day of a four-week fat-loss phase that starts 5 January and loses 0.5% a week. */
function reviewFatLossPhase(): Phase
{
    return new Phase(
        id: 'phase', type: PhaseType::FatLoss, start: F::day(5), end: PlanFixtures::date(2026, 2, 2),
        targetRatePercentPerWeek: -0.5,
    );
}

it('REV-003 progress places the trend inside the goal range', function () {
    // The trend falls 0.5% a week, the plan's own rate.
    $weighIns = F::trend(fn (int $day) => 82 * (1 - 0.005 * ($day - 5) / 7));
    $progress = ReviewProgressRules::make(reviewFatLossPhase(), $weighIns, F::day(18));
    expect($progress)->not->toBeNull()
        ->and($progress->position)->toBe(Position::Within)
        ->and($progress->weekRatePercent)->toEqualWithDelta(-0.5, 0.1)
        ->and($progress->targetRatePercent)->toBe(-0.5)
        ->and($progress->rateIsOnTarget)->toBeTrue();
});

it('REV-003 progress flags a trend above the range', function () {
    // The trend does not move: after two weeks of a 0.5% cut it sits above the goal range.
    $progress = ReviewProgressRules::make(reviewFatLossPhase(), F::trend(fn (int $day) => 82.0), F::day(18));
    expect($progress)->not->toBeNull()
        ->and($progress->position)->toBe(Position::Above)
        ->and($progress->weekRatePercent)->not->toBeNull()
        ->and($progress->weekRatePercent)->toEqualWithDelta(0, 1e-9)
        ->and($progress->rateIsOnTarget)->toBeFalse();
});

it('REV-003 progress uses the corridor in maintenance', function () {
    $maintenance = new Phase(
        id: 'phase', type: PhaseType::Maintenance, start: F::day(5), end: null, targetRatePercentPerWeek: 0,
    );
    $progress = ReviewProgressRules::make($maintenance, F::trend(fn (int $day) => 82.0), F::day(18));
    expect($progress)->not->toBeNull()
        ->and($progress->position)->toBe(Position::Within)
        ->and($progress->rateIsOnTarget)->toBeNull('maintenance has a corridor, not a rate band');
});

it('REV-003 progress needs a trend in the phase', function () {
    expect(ReviewProgressRules::make(
        reviewFatLossPhase(), new TrendWeight([], new DateTimeZone('UTC')), F::day(11),
    ))->toBeNull();
});

// PHP-only: Swift took a Set.

it('REV-003 an underperforming muscle listed twice is named once', function () {
    $summary = ReviewTraining::summary(
        ReviewTrainingFacts::empty(), [MuscleGroup::Back, MuscleGroup::Back, MuscleGroup::Quads], [], [], NextWeekKind::Normal,
    );
    expect($summary->underperformingMuscles)->toBe([MuscleGroup::Back, MuscleGroup::Quads]);
});
