<?php

namespace App\Domain\Progression;

use App\Domain\Model\JointPain;

/**
 * The weight of a session's sets after the recovery flags (PROG-018, PROG-051, PROG-053), on top of the PROG-020
 * targets. The caller gathers the history; this decides. Weights are in the user's unit throughout.
 */
final class RecoveryLoad
{
    /** PROG-018: the note under a weight cut for joint pain. */
    public const JOINT_PAIN_NOTE = 'Lighter this week after a lot of joint pain last time.';

    private const TOLERANCE = 1e-9;

    /**
     * The targets with the lowest weight any rule asks for, or the planned ones when none applies (deload, no last
     * week, no weight to build on). Candidates come in issue order, so a tie keeps PROG-051, then PROG-053, then
     * PROG-018. Every set takes the winning weight and its reps follow by PROG-024, without the PROG-025 far-weight
     * rule.
     */
    public static function apply(RecoveryLoadInputs $inputs): RecoveryLoadResult
    {
        $unchanged = new RecoveryLoadResult($inputs->planned, null);
        $rir = $inputs->rir;
        $lastWeight = $inputs->lastWeek[0]->weight ?? null;
        $plannedWeight = $inputs->planned[0]->weight ?? null;
        if ($inputs->isDeload || $rir === null || $lastWeight === null || $plannedWeight === null) {
            return $unchanged;
        }

        $step = $inputs->rules->step($inputs->isFatLoss) ?? 0.0;
        /** @var list<array{float, string}> $candidates */
        $candidates = [];

        // PROG-051
        if ($inputs->flaggedMuscle !== null) {
            $advice = Underperformance::advice($inputs->flaggedMuscle, count($inputs->planned), $lastWeight);
            $candidates[] = [$advice->weight, $advice->note];
        }
        // PROG-053
        $adjustment = OutOfRepRange::adjustment($inputs->history, $plannedWeight, $step);
        if ($adjustment !== null) {
            $candidates[] = [$adjustment->weight, $adjustment->note];
        }
        // PROG-018
        if ($inputs->lastWeekJointPain === JointPain::ALot && $step > 0) {
            $cut = $lastWeight * (1 - VolumeProgression::LOAD_CUT_PERCENT / 100);
            // The epsilon keeps an exact multiple like 45.0 from dropping a step on float noise.
            $rounded = floor($cut / $step + self::TOLERANCE) * $step;
            $candidates[] = [max(0.0, $rounded), self::JOINT_PAIN_NOTE];
        }

        $winner = null;
        foreach ($candidates as $candidate) {
            if ($winner === null || $candidate[0] < $winner[0] - self::TOLERANCE) {
                $winner = $candidate;
            }
        }
        if ($winner === null) {
            return $unchanged;
        }
        [$weight, $note] = $winner;

        // The weight is the app's choice, not one the user entered, so PROG-025 (too far from last week) does not
        // apply: every set with a rep target keeps one, from PROG-024.
        $targets = array_map(function (PlannedLoad $planned) use ($weight, $rir, $inputs) {
            if ($planned->reps === null) {
                return new PlannedLoad($weight, null);
            }
            $retarget = LoadProgression::retarget(new SetTarget($planned->weight, $planned->reps, $rir), $weight, null, $inputs->rules);
            if ($retarget->kind !== RetargetKind::Reps) {
                return new PlannedLoad($weight, null);
            }

            return new PlannedLoad($weight, $retarget->value > 0 ? $retarget->value : null);
        }, $inputs->planned);

        return new RecoveryLoadResult($targets, $note);
    }
}
