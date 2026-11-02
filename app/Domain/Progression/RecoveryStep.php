<?php

namespace App\Domain\Progression;

use App\Domain\Defaults\TBL051ReviewThresholds;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\PhaseType;

/** PROG-052: a recovery step recommended when several muscles underperform in one week. */
enum RecoveryStep: string
{
    /** Fat loss only: "it might not be the training, it might be the diet". */
    case DietBreakWeek = 'dietBreakWeek';
    case HalfSessionsForRestOfWeek = 'halfSessionsForRestOfWeek';
    case EarlyDeload = 'earlyDeload';

    /**
     * The steps to offer, recommended first. Empty below the TBL-051 muscle count.
     *
     * @param  list<MuscleGroup>  $underperformingMuscles
     * @return list<self>
     */
    public static function recommended(array $underperformingMuscles, PhaseType $phase): array
    {
        $count = count(array_unique(array_map(fn (MuscleGroup $muscle) => $muscle->value, $underperformingMuscles)));
        if ($count < TBL051ReviewThresholds::TRAINING_FATIGUE_UNDERPERFORMING_MUSCLES) {
            return [];
        }
        $training = [self::HalfSessionsForRestOfWeek, self::EarlyDeload];

        return $phase->isFatLoss() ? [self::DietBreakWeek, ...$training] : $training;
    }
}
