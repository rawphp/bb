<?php

namespace App\Domain\Phases;

use App\Domain\Defaults\TBL012TrainingDefaults;
use App\Domain\Defaults\TBL012TrainingDefaults\Column;
use App\Domain\Defaults\TBL012TrainingDefaults\PriorityPreset;
use App\Domain\Defaults\TBL012TrainingDefaults\Row;
use App\Domain\Defaults\TBL020MesocycleLength;
use App\Domain\Model\ClosedRange;
use App\Domain\Model\Mesocycle;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Priority;

/** What a phase means for training (PH-008, PH-009, PH-036, PH-039). */
final class PhaseTraining
{
    /** PH-036: sessions a week in a resensitization mesocycle (full body). */
    public static function resensitizationSessionsPerWeek(): ClosedRange
    {
        return new ClosedRange(2, 3);
    }

    /** PH-036: sessions per muscle per week. */
    public static function resensitizationSessionsPerMusclePerWeek(): ClosedRange
    {
        return new ClosedRange(1, 2);
    }

    /** PH-036: allowed length, in weeks. */
    public static function resensitizationWeeks(): ClosedRange
    {
        return TBL020MesocycleLength::resensitization()->allowedWeeks;
    }

    /** PH-036: default length, in weeks. */
    public static function defaultResensitizationWeeks(): int
    {
        return TBL020MesocycleLength::resensitization()->defaultWeeks;
    }

    /** PH-008, PH-039: the TBL-012 row for a phase type. */
    public static function defaults(PhaseType $type, bool $isResensitization = false): Row
    {
        return TBL012TrainingDefaults::row(Column::for($type, $isResensitization));
    }

    /**
     * PH-008: a priority for every muscle from the column's preset. Muscle gain starts everything at Grow; the user
     * picks up to 2 to emphasize.
     *
     * @param  ?list<MuscleGroup>  $muscles  null = every muscle group
     * @return array<string, Priority> keyed by MuscleGroup value
     */
    public static function defaultPriorities(Column $column, ?array $muscles = null): array
    {
        $priority = match (TBL012TrainingDefaults::row($column)->priorityPreset) {
            PriorityPreset::AllEmphasize => Priority::Emphasize,
            PriorityPreset::AllMaintain => Priority::Maintain,
            PriorityPreset::AllGrow, PriorityPreset::UpToTwoEmphasizeRestGrowOrMaintain => Priority::Grow,
        };

        $priorities = [];
        foreach ($muscles ?? MuscleGroup::cases() as $muscle) {
            $priorities[$muscle->value] = $priority;
        }

        return $priorities;
    }

    /**
     * PH-008, PH-009: everything the next mesocycle takes from its phase.
     *
     * @param  ?list<MuscleGroup>  $muscles  null = every muscle group
     */
    public static function mesocycleDefaults(PhaseType $type, bool $isResensitization = false, ?array $muscles = null): MesocycleDefaults
    {
        $column = Column::for($type, $isResensitization);

        return new MesocycleDefaults(
            column: $column,
            row: TBL012TrainingDefaults::row($column),
            priorities: self::defaultPriorities($column, $muscles),
        );
    }

    /**
     * PH-009: the column driving progression in $week (1-based) when the phase changed to $newType during week
     * $changeWeek. The mesocycle itself is not touched.
     */
    public static function progressionColumn(Mesocycle $mesocycle, int $week, PhaseType $newType, int $changeWeek): Column
    {
        return $week <= $changeWeek ? $mesocycle->trainingDefaultsColumn() : Column::for($newType, false);
    }

    /** PH-036: maintenance after fat loss pairs with resensitization; otherwise balanced. */
    public static function maintenanceDefaultsToResensitization(?PhaseType $previous): bool
    {
        return $previous?->isFatLoss() ?? false;
    }
}
