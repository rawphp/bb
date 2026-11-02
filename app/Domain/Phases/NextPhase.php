<?php

namespace App\Domain\Phases;

use App\Domain\Defaults\TBL010Phases;
use App\Domain\Model\ClosedRange;
use App\Domain\Model\PhaseType;

/** What comes after a phase (PH-030 to PH-034, PH-041). */
final class NextPhase
{
    /** PH-031: the longest maintenance after a cut, times the cut length. */
    public const MAX_MAINTENANCE_FACTOR = 2.0;

    /** PH-031: the shortest, when trend weight is still falling on maintenance calories. */
    public const MIN_MAINTENANCE_FACTOR_WHEN_STILL_FALLING = 0.25;

    /** PH-031: cuts in the past 12 months that make 1x the default. */
    public const FAT_LOSS_COUNT_FOR_FULL_LENGTH = 2;

    /** PH-034: maintenance should be at least this share of the previous cut (3/5). */
    public const MIN_MAINTENANCE_SHARE_NUMERATOR = 3;

    public const MIN_MAINTENANCE_SHARE_DENOMINATOR = 5;

    /** PH-033 */
    public static function maintenanceAfterGainWeeks(): ClosedRange
    {
        return new ClosedRange(4, 8);
    }

    /**
     * PH-032
     *
     * @return list<SequenceStep>
     */
    public static function gainFirstSequence(): array
    {
        return [
            new SequenceStep(PhaseType::MuscleGain, isResensitization: false, mesocycles: 1),
            new SequenceStep(PhaseType::Maintenance, isResensitization: true, mesocycles: 1),
            new SequenceStep(PhaseType::MuscleGain, isResensitization: false, mesocycles: null),
        ];
    }

    /**
     * PH-031 to PH-034: ordered options, the first is the default. A cut never follows a cut.
     *
     * @return list<NextPhaseOption>
     */
    public static function recommendation(PhaseType $after): array
    {
        return match ($after) {
            PhaseType::FatLoss, PhaseType::MiniCut => [NextPhaseOption::phase(PhaseType::Maintenance), NextPhaseOption::gainFirst()],
            PhaseType::MuscleGain => [NextPhaseOption::phase(PhaseType::Maintenance), NextPhaseOption::phase(PhaseType::MiniCut)],
            PhaseType::Maintenance => [NextPhaseOption::phase(PhaseType::MuscleGain), NextPhaseOption::phase(PhaseType::FatLoss)],
        };
    }

    /** PH-030 */
    public static function transition(PhaseSummary $summary): PhaseTransition
    {
        $options = self::recommendation($summary->type);
        $recommended = array_filter(array_map(fn (NextPhaseOption $option) => $option->phase, $options));

        return new PhaseTransition(
            summary: $summary,
            recommendation: $options,
            alternatives: array_values(array_filter(
                PhaseType::cases(), fn (PhaseType $type) => ! in_array($type, $recommended, true),
            )),
        );
    }

    /** PH-031 */
    public static function maintenanceAfterFatLoss(
        int $fatLossWeeks, int $fatLossPhasesInPast12Months, bool $trendStillFallingOnMaintenance, int $mesocycleWeeks,
    ): MaintenanceLength {
        $factors = TBL010Phases::maintenanceAfterCutLengthFactor();
        $factor = $fatLossPhasesInPast12Months >= self::FAT_LOSS_COUNT_FOR_FULL_LENGTH ? $factors->upper : $factors->lower;
        $mesocycles = max(1, (int) round($factor * $fatLossWeeks / $mesocycleWeeks));
        $lowerFactor = $trendStillFallingOnMaintenance ? self::MIN_MAINTENANCE_FACTOR_WHEN_STILL_FALLING : $factors->lower;
        $lower = max(1, (int) ceil($lowerFactor * $fatLossWeeks - 1e-9));
        $upper = (int) floor(self::MAX_MAINTENANCE_FACTOR * $fatLossWeeks + 1e-9);
        $defaultWeeks = $mesocycles * $mesocycleWeeks;

        return new MaintenanceLength(
            defaultWeeks: $defaultWeeks,
            allowedWeeks: new ClosedRange(min($lower, $defaultWeeks), max($upper, $defaultWeeks)),
        );
    }

    /**
     * PH-041
     *
     * @return list<FinalReviewOption>
     */
    public static function finalReviewOptions(PhaseType $phase, int $currentWeeks): array
    {
        $options = [];
        $maxWeeks = TBL010Phases::row($phase)->maxWeeks;
        if ($maxWeeks === null) {
            $options[] = FinalReviewOption::keepGoal(null);
        } elseif ($maxWeeks - $currentWeeks > 0) {
            $options[] = FinalReviewOption::keepGoal($maxWeeks - $currentWeeks);
        }

        return [...$options, FinalReviewOption::chooseNewGoal(), FinalReviewOption::endPlan()];
    }
}
