<?php

namespace App\Domain\Review;

use App\Domain\Model\JointPain;
use App\Domain\Model\MuscleGroup;

/** REV-003 step 3. */
final class ReviewTraining
{
    /**
     * @param  list<MuscleGroup>  $underperforming
     * @param  array<string, int>  $ceilings  each muscle's weekly ceiling in sets (MRV for the phase), keyed by MuscleGroup value
     * @param  list<JointPainFlag>  $jointPain
     */
    public static function summary(
        ReviewTrainingFacts $facts,
        array $underperforming,
        array $ceilings,
        array $jointPain,
        NextWeekKind $nextWeek,
    ): ReviewTrainingSummary {
        $atCeiling = [];
        foreach ($facts->plannedSetsByMuscle as $muscle => $sets) {
            $ceiling = $ceilings[$muscle] ?? 0;
            if ($ceiling > 0 && $sets >= $ceiling) {
                $atCeiling[] = MuscleGroup::from((string) $muscle);
            }
        }

        return new ReviewTrainingSummary(
            sessionsDone: $facts->doneSessions,
            sessionsSkipped: $facts->skippedSessions,
            sessionsMissed: max(0, $facts->plannedSessions - $facts->doneSessions - $facts->skippedSessions),
            setsDone: $facts->loggedSets,
            setsPlanned: $facts->plannedSets,
            underperformingMuscles: self::byTitle($underperforming),
            musclesAtCeiling: self::byTitle($atCeiling),
            jointPain: $jointPain,
            nextWeek: $nextWeek,
        );
    }

    /**
     * FB-002: the worst answer per exercise, "None" left out, in order of first appearance.
     *
     * @param  list<array{exerciseName: string, level: JointPain}>  $answers
     * @return list<JointPainFlag>
     */
    public static function jointPainFlags(array $answers): array
    {
        $rank = fn (JointPain $level) => (int) array_search($level, JointPain::cases(), true);
        /** @var list<string> $order */
        $order = [];
        /** @var array<string, JointPain> $worst */
        $worst = [];
        foreach ($answers as $answer) {
            $name = $answer['exerciseName'];
            $kept = $worst[$name] ?? null;
            if ($kept === null) {
                $order[] = $name;
                $worst[$name] = $answer['level'];
            } elseif ($rank($answer['level']) > $rank($kept)) {
                $worst[$name] = $answer['level'];
            }
        }

        $flags = [];
        foreach ($order as $name) {
            if ($worst[$name] !== JointPain::None) {
                $flags[] = new JointPainFlag($name, $worst[$name]);
            }
        }

        return $flags;
    }

    /**
     * Next week is a deload, the last week before one, or an ordinary week. `$mesocycleWeek` is next week's place in
     * its mesocycle from 1; null when no mesocycle covers it. `$isDeload` is the week's own deload flag.
     */
    public static function nextWeekKind(?int $mesocycleWeek, ?int $mesocycleWeeks, bool $isDeload): NextWeekKind
    {
        if ($isDeload) {
            return NextWeekKind::Deload;
        }
        if ($mesocycleWeek === null || $mesocycleWeeks === null) {
            return NextWeekKind::Normal;
        }
        if ($mesocycleWeek === $mesocycleWeeks) {
            return NextWeekKind::Deload;
        }

        return $mesocycleWeek === $mesocycleWeeks - 1 ? NextWeekKind::LastHardWeek : NextWeekKind::Normal;
    }

    /**
     * @param  list<MuscleGroup>  $muscles
     * @return list<MuscleGroup> without repeats, sorted by title
     */
    private static function byTitle(array $muscles): array
    {
        $unique = [];
        foreach ($muscles as $muscle) {
            $unique[$muscle->value] = $muscle;
        }
        $sorted = array_values($unique);
        usort($sorted, fn (MuscleGroup $a, MuscleGroup $b) => strcmp($a->title(), $b->title()));

        return $sorted;
    }
}
