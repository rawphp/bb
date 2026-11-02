<?php

use App\Domain\Defaults\TBL051ReviewThresholds;
use App\Domain\Model\ClosedRange;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\PhaseType;
use App\Domain\Progression\LoggedExercise;
use App\Domain\Progression\LoggedSet;
use App\Domain\Progression\RecoveryStep;
use App\Domain\Progression\Underperformance;

/**
 * @param  list<MuscleGroup>  $muscles
 * @return list<LoggedExercise>
 */
function recoveryStepSession(array $muscles, int $reps): array
{
    return array_map(
        fn (MuscleGroup $muscle) => new LoggedExercise(
            $muscle->title(), $muscle, new ClosedRange(8, 12), [new LoggedSet(100, 10, $reps)],
        ),
        $muscles,
    );
}

it('PROG-052 fewer than 2 underperforming muscles recommends nothing', function () {
    expect(RecoveryStep::recommended([], PhaseType::FatLoss))->toBe([])
        ->and(RecoveryStep::recommended([MuscleGroup::Chest], PhaseType::FatLoss))->toBe([])
        ->and(TBL051ReviewThresholds::TRAINING_FATIGUE_UNDERPERFORMING_MUSCLES)->toBe(2);
});

it('PROG-052 counts muscles flagged by a session of this week, judged on the whole history', function () {
    $lastWeek = [recoveryStepSession([MuscleGroup::Chest, MuscleGroup::Back], 7), recoveryStepSession([MuscleGroup::Quads], 7)];
    // Chest and back are trained once a week: their run spans the two weeks.
    $thisWeek = [recoveryStepSession([MuscleGroup::Chest, MuscleGroup::Back], 7), recoveryStepSession([MuscleGroup::Quads], 10)];
    $history = [...$lastWeek, ...$thisWeek];

    expect(Underperformance::flaggedMusclesThisWeek($history, 2))->toBe([MuscleGroup::Chest, MuscleGroup::Back])
        ->and(RecoveryStep::recommended([MuscleGroup::Chest, MuscleGroup::Back], PhaseType::MuscleGain))
        ->toBe([RecoveryStep::HalfSessionsForRestOfWeek, RecoveryStep::EarlyDeload]);

    // A flag raised last week does not carry into a week whose sessions did not train the muscle.
    $carried = [
        recoveryStepSession([MuscleGroup::Chest], 7), recoveryStepSession([MuscleGroup::Chest], 7),
        recoveryStepSession([MuscleGroup::Back], 10),
    ];
    expect(Underperformance::flaggedMusclesThisWeek($carried, 1))->toBe([])
        ->and(Underperformance::flaggedMusclesThisWeek($carried, 2))->toBe([MuscleGroup::Chest])
        ->and(Underperformance::flaggedMusclesThisWeek($carried, 0))->toBe([])
        ->and(Underperformance::flaggedMusclesThisWeek($carried, 5))->toBe([MuscleGroup::Chest]);
});

it('PROG-052 outside fat loss recommends a half session or an early deload', function (PhaseType $phase) {
    expect(RecoveryStep::recommended([MuscleGroup::Chest, MuscleGroup::Back], $phase))
        ->toBe([RecoveryStep::HalfSessionsForRestOfWeek, RecoveryStep::EarlyDeload]);
})->with([PhaseType::Maintenance, PhaseType::MuscleGain]);

it('PROG-052 during fat loss recommends a diet break week first', function (PhaseType $phase) {
    // PLAN-009: Mini-cut counts as Fat loss.
    expect(RecoveryStep::recommended([MuscleGroup::Chest, MuscleGroup::Back, MuscleGroup::Quads], $phase))
        ->toBe([RecoveryStep::DietBreakWeek, RecoveryStep::HalfSessionsForRestOfWeek, RecoveryStep::EarlyDeload]);
})->with([PhaseType::FatLoss, PhaseType::MiniCut]);
