<?php

namespace App\Domain\Plan;

use App\Domain\Model\Phase;
use App\Domain\Model\PhaseType;
use Closure;

/** The two prebuilt 24-week plans (PLAN-030). Phases are split so each one passes TBL-010, SAFE-006 and SAFE-007. */
enum PrebuiltPlan: string
{
    case MuscleGain = 'muscleGain';
    case FatLoss = 'fatLoss';

    public const LENGTH_IN_WEEKS = 24;

    /**
     * The plan starting at a grid week. `$newPhaseId` names each new phase, in order.
     *
     * @param  Closure(): string  $newPhaseId
     */
    public function layout(int $startingAtWeek, PlanWeekGrid $grid, Closure $newPhaseId): PlanLayout
    {
        [$phases, $mesocycles, $activeRest] = $this->outline();

        return new PlanLayout(
            phases: array_map(fn (array $phase) => new Phase(
                id: $newPhaseId(),
                type: $phase[0],
                start: $grid->start($startingAtWeek + $phase[2]),
                end: $grid->start($startingAtWeek + $phase[3]),
                targetRatePercentPerWeek: $phase[1],
            ), $phases),
            mesocycles: array_map(
                fn (array $mesocycle) => new PlannedMesocycle($startingAtWeek + $mesocycle[0], $mesocycle[1]),
                $mesocycles,
            ),
            activeRestWeeks: array_map(fn (int $week) => $startingAtWeek + $week, $activeRest),
        );
    }

    /**
     * Phases as [type, rate, first week, end week (exclusive)], mesocycles as [start week, weeks], and the active
     * rest weeks, all from week 0.
     *
     * @return array{list<array{PhaseType, float, int, int}>, list<array{int, int}>, list<int>}
     */
    private function outline(): array
    {
        return match ($this) {
            self::MuscleGain => [
                [
                    [PhaseType::MuscleGain, 0.5, 0, 9], [PhaseType::MuscleGain, 0.375, 9, 16],
                    [PhaseType::Maintenance, 0.0, 16, 18], [PhaseType::FatLoss, -0.75, 18, 24],
                ],
                [[0, 5], [5, 4], [9, 4], [13, 4], [18, 6]],
                [16, 17],
            ],
            self::FatLoss => [
                [[PhaseType::FatLoss, -0.75, 0, 14], [PhaseType::Maintenance, 0.0, 14, 16], [PhaseType::FatLoss, -0.75, 16, 24]],
                [[0, 5], [5, 5], [10, 5], [16, 4], [20, 4]],
                [14, 15],
            ],
        };
    }
}
