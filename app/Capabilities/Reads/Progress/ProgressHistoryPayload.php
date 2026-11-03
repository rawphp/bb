<?php

namespace App\Capabilities\Reads\Progress;

use App\Capabilities\Progress\Data\MesocycleHistoryEntry;
use App\Capabilities\Progress\Data\PhaseHistoryEntry;
use App\Capabilities\Progress\Data\UsedExercise;
use App\Capabilities\Support\Line;
use App\Capabilities\Support\Status;
use App\Capabilities\Support\Tone;
use App\Domain\Progress\MesocycleHistory;
use App\Domain\Progress\MuscleWeeklySets;
use App\Domain\Progress\PhaseHistory;
use App\Domain\Progress\ProgressMesocycle;
use App\Models\Exercise;
use App\Support\Plan\PlanWords;
use App\Support\Progress\ProgressSnapshot;

/** The past phases, past mesocycles and the exercises with a logged set (ANA-006 to ANA-009). */
final class ProgressHistoryPayload
{
    /**
     * ANA-006, ANA-008.
     *
     * @return list<PhaseHistoryEntry>
     */
    public static function phases(ProgressSnapshot $snapshot): array
    {
        $unit = $snapshot->weightUnit();
        $entries = PhaseHistory::entries(
            $snapshot->phases(), $snapshot->mesocycles(), $snapshot->trend(), $snapshot->today(), $snapshot->zone(),
        );

        return array_map(fn ($entry) => new PhaseHistoryEntry(
            id: $entry->id,
            title: $entry->type->title(),
            dates_label: ProgressWords::dates($entry->start, $entry->end),
            lines: [
                new Line('Weight change', $entry->weightChangeKg === null ? '–' : PlanWords::weightChange($entry->weightChangeKg, $unit), Tone::Neutral->value),
                new Line('Average rate', $entry->averageRatePercentPerWeek === null ? '–' : sprintf('%+.2f%% per week', $entry->averageRatePercentPerWeek), Tone::Neutral->value),
            ],
            mesocycles: array_map(
                fn (ProgressMesocycle $meso) => new Line($meso->name, PlanWords::weeks($meso->weeks), Tone::Neutral->value),
                $entry->mesocycles,
            ),
        ), $entries);
    }

    /**
     * ANA-007.
     *
     * @return list<MesocycleHistoryEntry>
     */
    public static function mesocycles(ProgressSnapshot $snapshot): array
    {
        $entries = MesocycleHistory::entries(
            $snapshot->mesocycles(), $snapshot->phases(), $snapshot->workingSets(), $snapshot->today(), $snapshot->zone(),
        );

        return array_map(fn ($entry) => new MesocycleHistoryEntry(
            id: $entry->id,
            title: $entry->name,
            dates_label: ProgressWords::dates($entry->start, $entry->end),
            phase: $entry->phaseType === null ? null : new Status($entry->phaseType->title(), Tone::Info->value),
            sets_title: $entry->weeklySets === [] ? 'No sets logged' : 'Average sets per week',
            sets: array_map(
                fn (MuscleWeeklySets $sets) => new Line($sets->muscle->title(), sprintf('%.1f', $sets->averageSets), Tone::Neutral->value),
                $entry->weeklySets,
            ),
        ), $entries);
    }

    /**
     * ANA-009, EX-013.
     *
     * @return list<UsedExercise>
     */
    public static function exercises(ProgressSnapshot $snapshot): array
    {
        return array_map(
            fn (Exercise $exercise) => new UsedExercise((string) $exercise->id, $exercise->name, $exercise->muscle_group->title()),
            $snapshot->usedExercises(),
        );
    }
}
