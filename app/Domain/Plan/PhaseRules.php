<?php

namespace App\Domain\Plan;

use App\Domain\Defaults\TBL010Phases;
use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\Phase;
use App\Domain\Model\PhaseType;

/** Phase length and rate limits (PH-003 to PH-006, TBL-010, SAFE-006, SAFE-007). */
final class PhaseRules
{
    /**
     * Checks a phase against TBL-010. `$ratePercentPerWeek` null means training-only (PLAN-007): not checked.
     * `$weeks` null means no end (PH-005).
     *
     * A fat-loss or Mini-cut cap counts deficit weeks only, so diet-break weeks are taken off (SAFE-006, PLAN-009,
     * PLAN-025). Muscle-gain deloads count towards the cap (SAFE-007).
     */
    public static function validate(
        PhaseType $type, ?int $weeks, ?float $ratePercentPerWeek, int $dietBreakWeeks = 0,
    ): ?PhaseRefusal {
        $row = TBL010Phases::row($type);
        if ($weeks !== null) {
            if ($weeks < $row->minWeeks) {
                return PhaseRefusal::tooShort($row->minWeeks);
            }
            $counted = $type->isFatLoss() ? $weeks - $dietBreakWeeks : $weeks;
            if ($row->maxWeeks !== null && $counted > $row->maxWeeks) {
                return PhaseRefusal::tooLong($row->maxWeeks);
            }
        } elseif ($row->maxWeeks !== null) {
            return PhaseRefusal::endDateRequired();
        }
        if ($ratePercentPerWeek !== null && ! $row->rateRangePercentPerWeek->contains($ratePercentPerWeek)) {
            return PhaseRefusal::rateOutOfRange($row->rateRangePercentPerWeek);
        }

        return null;
    }

    /**
     * PH-004: the rate a new phase starts with. Fat loss uses "Highest success", Mini-cut and Maintenance their
     * TBL-010 default, Muscle gain the preset for the user's experience.
     */
    public static function defaultRatePercentPerWeek(PhaseType $type, ExperienceLevel $experience): float
    {
        return match ($type) {
            PhaseType::MuscleGain => TBL010Phases::muscleGainRatePercentPerWeek($experience),
            PhaseType::FatLoss, PhaseType::MiniCut, PhaseType::Maintenance => TBL010Phases::row($type)->presets[0]->ratePercentPerWeek ?? 0.0,
        };
    }

    /**
     * PH-006: change a phase's length; the end moves to a week boundary. Only the length is checked.
     *
     * @throws PhaseRefused
     */
    public static function adjustingLength(Phase $phase, ?int $weeks, PlanWeekGrid $grid, int $dietBreakWeeks = 0): Phase
    {
        $startWeek = $grid->isBoundary($phase->start) ? $grid->weekIndexContaining($phase->start) : null;
        if ($startWeek === null) {
            throw new PhaseRefused(PhaseRefusal::notOnWeekBoundary());
        }
        $refusal = self::validate($phase->type, $weeks, null, $dietBreakWeeks);
        if ($refusal !== null) {
            throw new PhaseRefused($refusal);
        }

        return new Phase(
            id: $phase->id,
            type: $phase->type,
            start: $phase->start,
            end: $weeks === null ? null : $grid->start($startWeek + $weeks),
            targetRatePercentPerWeek: $phase->targetRatePercentPerWeek,
            eventDate: $phase->eventDate,
        );
    }
}
