<?php

use App\Domain\Defaults\TBL040LoadSteps\LoadType;
use App\Domain\Model\ClosedRange;
use App\Domain\Model\JointPain;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\WeightUnit;
use App\Domain\Progression\LoadProgression;
use App\Domain\Progression\LoadRules;
use App\Domain\Progression\LoggedExercise;
use App\Domain\Progression\LoggedSet;
use App\Domain\Progression\PlannedLoad;
use App\Domain\Progression\RecoveryLoad;
use App\Domain\Progression\RecoveryLoadInputs;
use App\Domain\Progression\RecoveryLoadResult;
use App\Domain\Progression\Retarget;
use App\Domain\Progression\SetLoad;
use App\Domain\Progression\SetTarget;
use App\Domain\Progression\Underperformance;

/*
 * PROG-018, PROG-051, PROG-053 on the PROG-020 targets, and PROG-024 for the reps that follow the weight.
 *
 * Bench in a barbell 8 to 12 range, 2.5 kg steps. Last week 100 kg for 10, 9 and 8; PROG-020 plans 102.5 kg for the
 * same reps, at 2 RIR.
 */

function recoveryRules(LoadType $type = LoadType::BarbellOrSmithMachine, float $step = 2.5): LoadRules
{
    return new LoadRules($type, WeightUnit::Kg, false, $step, new ClosedRange(8, 12));
}

/** @return list<SetLoad> */
function recoveryLastWeek(): array
{
    return [new SetLoad(100, 10), new SetLoad(100, 9), new SetLoad(100, 8)];
}

/** @return list<PlannedLoad> */
function recoveryPlanned(): array
{
    return [new PlannedLoad(102.5, 10), new PlannedLoad(102.5, 9), new PlannedLoad(102.5, 8)];
}

/** @param list<int> $reps */
function recoverySession(array $reps): LoggedExercise
{
    return new LoggedExercise(
        'Bench Press', MuscleGroup::Chest, new ClosedRange(8, 12),
        array_map(fn (int $done) => new LoggedSet(100, 10, $done), $reps),
    );
}

/**
 * @param  ?list<PlannedLoad>  $planned
 * @param  ?list<SetLoad>  $lastWeek
 * @param  list<LoggedExercise>  $history
 */
function recoveryApply(
    ?array $planned = null, ?array $lastWeek = null, ?int $rir = 2, bool $isDeload = false,
    ?MuscleGroup $flagged = null, array $history = [], ?JointPain $pain = null, ?LoadRules $rules = null,
): RecoveryLoadResult {
    return RecoveryLoad::apply(new RecoveryLoadInputs(
        planned: $planned ?? recoveryPlanned(), lastWeek: $lastWeek ?? recoveryLastWeek(), rir: $rir, isDeload: $isDeload,
        isFatLoss: false, rules: $rules ?? recoveryRules(), muscle: MuscleGroup::Chest, flaggedMuscle: $flagged,
        history: $history, lastWeekJointPain: $pain,
    ));
}

/** @return list<?float> */
function recoveryWeights(RecoveryLoadResult $result): array
{
    return array_map(fn (PlannedLoad $target) => $target->weight, $result->targets);
}

/** @return list<?int> */
function recoveryReps(RecoveryLoadResult $result): array
{
    return array_map(fn (PlannedLoad $target) => $target->reps, $result->targets);
}

const RECOVERY_FLAGGED_NOTE = 'Chest missed its rep targets 2 sessions running. Hold sets and repeat the weight to recover.';
const RECOVERY_BELOW_NOTE = 'Your reps were below 8 to 12 on most sets for 2 sessions, so the weight goes down one step.';
const RECOVERY_ABOVE_NOTE = 'Your reps were above 8 to 12 on most sets for 2 sessions, so the weight goes up one step.';
const RECOVERY_PAIN_NOTE = 'Lighter this week after a lot of joint pain last time.';

it('PROG-051 a flagged muscle repeats last week\'s weight with the advice note', function () {
    $result = recoveryApply(flagged: MuscleGroup::Chest);
    expect(recoveryWeights($result))->toBe([100.0, 100.0, 100.0])
        ->and($result->note)->toBe(RECOVERY_FLAGGED_NOTE)
        ->and($result->note)->toBe(Underperformance::advice(MuscleGroup::Chest, 3, 100)->note);
});

it('PROG-053 reps below the range take the weight down one step from the target', function () {
    $low = recoverySession([6, 7, 9]);
    $result = recoveryApply(history: [$low, $low]);
    expect(recoveryWeights($result))->toBe([100.0, 100.0, 100.0])
        ->and($result->note)->toBe(RECOVERY_BELOW_NOTE);
});

it('PROG-053 reps above the range take the weight up one step from the target', function () {
    $high = recoverySession([14, 13, 11]);
    $result = recoveryApply(history: [$high, $high]);
    expect(recoveryWeights($result))->toBe([105.0, 105.0, 105.0])
        ->and($result->note)->toBe(RECOVERY_ABOVE_NOTE);
});

it('PROG-053 no rule when the history is in range', function () {
    $result = recoveryApply(history: [recoverySession([10, 10, 9]), recoverySession([10, 10, 9])]);
    expect($result->targets)->toEqual(recoveryPlanned())
        ->and($result->note)->toBeNull();
});

it('PROG-018 a lot of pain cuts last week\'s weight 10%, rounded down to the step', function () {
    // 102.5 kg less 10% is 92.25 kg, so the step below: 90 kg.
    $result = recoveryApply(planned: [new PlannedLoad(105, 10)], lastWeek: [new SetLoad(102.5, 10)], pain: JointPain::ALot);
    expect(recoveryWeights($result))->toBe([90.0])
        ->and($result->note)->toBe('Lighter this week after a lot of joint pain last time.')
        ->and(RecoveryLoad::JOINT_PAIN_NOTE)->toBe(RECOVERY_PAIN_NOTE);
});

it('PROG-018 uses the equipment step, not the nearest one', function () {
    // Dumbbells in 2 kg steps: 30 kg less 10% is 27 kg, rounded down to 26.
    $result = recoveryApply(
        planned: [new PlannedLoad(30, 10)], lastWeek: [new SetLoad(30, 10)], pain: JointPain::ALot,
        rules: recoveryRules(LoadType::Dumbbell, 2),
    );
    expect(recoveryWeights($result))->toBe([26.0]);
});

it('PROG-018 an exact step multiple does not drop a step', function () {
    // 50 kg less 10% is exactly 45 kg, a multiple of 2.5: no float noise may take it to 42.5.
    $result = recoveryApply(planned: [new PlannedLoad(52.5, 10)], lastWeek: [new SetLoad(50, 10)], pain: JointPain::ALot);
    expect(recoveryWeights($result))->toBe([45.0]);
});

it('PROG-018 only A lot of pain counts, and it needs a step', function () {
    expect(recoveryApply(pain: JointPain::Moderate)->targets)->toEqual(recoveryPlanned())
        ->and(recoveryApply(pain: JointPain::Low)->note)->toBeNull()
        // No step to round to (a zero override): the cut has no weight to land on.
        ->and(recoveryApply(pain: JointPain::ALot, rules: recoveryRules(step: 0))->note)->toBeNull();
});

it('PROG-018 the lowest weight wins and its note shows', function () {
    // A flag holds at 100, pain cuts to 90, a high history would go to 105.
    $high = recoverySession([14, 13, 11]);
    $result = recoveryApply(flagged: MuscleGroup::Chest, history: [$high, $high], pain: JointPain::ALot);
    expect(recoveryWeights($result))->toBe([90.0, 90.0, 90.0])
        ->and($result->note)->toBe(RECOVERY_PAIN_NOTE);
});

it('PROG-051 a flag beats a higher out-of-range weight', function () {
    $high = recoverySession([14, 13, 11]);
    $result = recoveryApply(flagged: MuscleGroup::Chest, history: [$high, $high]);
    expect(recoveryWeights($result))->toBe([100.0, 100.0, 100.0])
        ->and($result->note)->toBe(RECOVERY_FLAGGED_NOTE);
});

it('PROG-053 a lower out-of-range weight beats the flag', function () {
    // The target is 102.5 kg, but the flag holds 110 kg from last week: below the range goes down to 100.
    $low = recoverySession([6, 7, 9]);
    $result = recoveryApply(
        lastWeek: [new SetLoad(110, 10), new SetLoad(110, 9), new SetLoad(110, 8)],
        flagged: MuscleGroup::Chest, history: [$low, $low],
    );
    expect(recoveryWeights($result))->toBe([100.0, 100.0, 100.0])
        ->and($result->note)->toBe(RECOVERY_BELOW_NOTE);
});

it('PROG-051 a tie keeps the flag note, then out of range, then pain', function () {
    $low = recoverySession([6, 7, 9]);
    // Flag and out of range both land on 100 kg.
    expect(recoveryApply(flagged: MuscleGroup::Chest, history: [$low, $low])->note)->toBe(RECOVERY_FLAGGED_NOTE);
    // Out of range (92.5 - 2.5 = 90) and pain (100 less 10%, 90) both land on 90 kg.
    $tied = recoveryApply(planned: [new PlannedLoad(92.5, 10)], history: [$low, $low], pain: JointPain::ALot);
    expect(recoveryWeights($tied))->toBe([90.0])
        ->and($tied->note)->toBe(RECOVERY_BELOW_NOTE);
});

it('PROG-024 the reps follow the winning weight from each planned set', function () {
    // Holding 100 kg instead of 102.5 kg at the same effort buys reps: 10, 9, 8 becomes 11, 10, 9.
    expect(recoveryReps(recoveryApply(flagged: MuscleGroup::Chest)))->toBe([11, 10, 9]);
    // 105 kg instead takes them: 9, 8, 7.
    $high = recoverySession([14, 13, 11]);
    expect(recoveryReps(recoveryApply(history: [$high, $high])))->toBe([9, 8, 7]);
});

it('PROG-024 retargets exactly as an entered weight would', function () {
    $result = recoveryApply(flagged: MuscleGroup::Chest);
    foreach (recoveryPlanned() as $index => $target) {
        $expected = LoadProgression::retarget(
            new SetTarget($target->weight, $target->reps ?? 0, 2), enteredWeight: 100, lastWeekWeight: 100, rules: recoveryRules(),
        );
        expect($expected)->toEqual(Retarget::reps($result->targets[$index]->reps ?? 0));
    }
});

it('PROG-024 a recovery weight far from last week still gets a rep target', function () {
    // The app picks this weight, the user did not enter it, so PROG-025 does not apply: every planned set keeps a
    // rep target. 10 kg dumbbells last week; out of range above adds a 2 kg step: 12 kg is 20% up.
    $high = recoverySession([14, 13]);
    $result = recoveryApply(
        planned: [new PlannedLoad(10, 10)], lastWeek: [new SetLoad(10, 10)], history: [$high, $high],
        rules: recoveryRules(LoadType::Dumbbell, 2),
    );
    expect(recoveryWeights($result))->toBe([12.0])
        ->and(recoveryReps($result))->toBe([3])
        ->and($result->note)->toBe(RECOVERY_ABOVE_NOTE);
});

it('PROG-024 a cut more than 15% below last week still gets a rep target per set', function () {
    // 10 kg dumbbells last week, below the range twice: one 2 kg step down is 8 kg, 20% under last week.
    $dumbbell = recoveryRules(LoadType::Dumbbell, 2);
    $low = recoverySession([6, 7]);
    $result = recoveryApply(
        planned: [new PlannedLoad(10, 10), new PlannedLoad(10, 9)],
        lastWeek: [new SetLoad(10, 10), new SetLoad(10, 9)], history: [$low, $low], rules: $dumbbell,
    );
    expect(recoveryWeights($result))->toBe([8.0, 8.0])
        ->and(recoveryReps($result))->toBe([20, 19])
        ->and($result->note)->toBe(RECOVERY_BELOW_NOTE);

    // A joint-pain cut on light weight lands the same way: 10 kg less 10% is 9 kg, the step below is 8 kg.
    $pained = recoveryApply(
        planned: [new PlannedLoad(10, 10)], lastWeek: [new SetLoad(10, 10)], pain: JointPain::ALot, rules: $dumbbell,
    );
    expect(recoveryWeights($pained))->toBe([8.0])
        ->and($pained->targets[0]->reps)->not->toBeNull();
});

it('PROG-051 and PROG-018 build on last week\'s first set when the sets were uneven', function () {
    $uneven = [new SetLoad(100, 10), new SetLoad(97.5, 9), new SetLoad(95, 8)];
    expect(recoveryWeights(recoveryApply(lastWeek: $uneven, flagged: MuscleGroup::Chest)))->toBe([100.0, 100.0, 100.0])
        // 100 kg less 10% is 90 kg; cutting from the last set (95 kg) would give 85.
        ->and(recoveryWeights(recoveryApply(lastWeek: $uneven, pain: JointPain::ALot)))->toBe([90.0, 90.0, 90.0]);
});

it('PROG-040 a deload week changes nothing', function () {
    $low = recoverySession([6, 7, 9]);
    $result = recoveryApply(isDeload: true, flagged: MuscleGroup::Chest, history: [$low, $low], pain: JointPain::ALot);
    expect($result->targets)->toEqual(recoveryPlanned())
        ->and($result->note)->toBeNull();
});

it('PROG-020 no last week changes nothing', function () {
    $result = recoveryApply(lastWeek: [], flagged: MuscleGroup::Chest, pain: JointPain::ALot);
    expect($result->targets)->toEqual(recoveryPlanned())
        ->and($result->note)->toBeNull();
});

it('PROG-020 no weight to hold changes nothing', function () {
    $bodyweight = [new PlannedLoad(null, 12)];
    $result = recoveryApply(planned: $bodyweight, lastWeek: [new SetLoad(null, 11)], flagged: MuscleGroup::Chest, pain: JointPain::ALot);
    expect($result->targets)->toEqual($bodyweight)
        ->and($result->note)->toBeNull()
        // A planned weight with nothing logged last week to hold is the same.
        ->and(recoveryApply(lastWeek: [new SetLoad(null, 11)], flagged: MuscleGroup::Chest)->targets)->toEqual(recoveryPlanned());
});

it('PROG-030 with no RIR nothing changes', function () {
    expect(recoveryApply(rir: null, flagged: MuscleGroup::Chest)->targets)->toEqual(recoveryPlanned());
});

it('PROG-051 no rule leaves the targets alone', function () {
    $result = recoveryApply();
    expect($result->targets)->toEqual(recoveryPlanned())
        ->and($result->note)->toBeNull();
});

it('PROG-024 a set with no rep target keeps none at the new weight', function () {
    $planned = [new PlannedLoad(102.5, 10), new PlannedLoad(102.5, null)];
    $result = recoveryApply(planned: $planned, flagged: MuscleGroup::Chest);
    expect(recoveryWeights($result))->toBe([100.0, 100.0])
        ->and(recoveryReps($result))->toBe([11, null]);
});

it('PROG-053 a weight that lands on 0 has no rep target', function () {
    // Planned at one step, two sessions below the range take it down to nothing: PROG-024 has no load to work from.
    $low = recoverySession([6, 7, 6]);
    $result = recoveryApply(
        planned: [new PlannedLoad(2.5, 10), new PlannedLoad(2.5, 9)],
        lastWeek: [new SetLoad(2.5, 10), new SetLoad(2.5, 9)],
        history: [$low, $low],
    );
    expect(recoveryWeights($result))->toBe([0.0, 0.0])
        ->and(recoveryReps($result))->toBe([null, null]);
});
