<?php

namespace App\Capabilities\Reads\Exercises;

use App\Capabilities\Exercises\Data\ExerciseHistoryDay;
use App\Capabilities\Exercises\Data\ExerciseHistorySet;
use App\Capabilities\Exercises\Data\ExerciseLoadStep;
use App\Capabilities\Exercises\Data\ExerciseShowOutput;
use App\Capabilities\Reads\Progress\ExerciseHistoryPayload;
use App\Capabilities\Reads\Workout\WorkoutWords;
use App\Capabilities\Support\Tone;
use App\Capabilities\Train\Data\Measure;
use App\Capabilities\Train\Data\Pill;
use App\Domain\Defaults\TBL040LoadSteps;
use App\Domain\Model\UnitConversion;
use App\Domain\Model\WeightUnit;
use App\Models\Exercise;
use App\Models\WorkoutSet;
use Illuminate\Support\Collection;

/**
 * One exercise: the Detail tab (EX-002, EX-005, EX-007, EX-010, EX-012) and the History tab (EX-002). The history is the
 * one exercise_history_show builds, in this screen's contract.
 */
final class ExercisePayload
{
    /**
     * @param  bool  $inCurrentMesocycle  whether a slot of the current mesocycle names the exercise
     * @param  ?float  $stepKg  the user's own load step, as stored
     * @param  Collection<int, WorkoutSet>  $sets  the user's logged sets of it in position order, each with its session loaded
     */
    public static function build(
        Exercise $exercise,
        int $userId,
        WeightUnit $unit,
        bool $microPlates,
        bool $inCurrentMesocycle,
        ?string $note,
        ?float $stepKg,
        Collection $sets,
    ): ExerciseShowOutput {
        return new ExerciseShowOutput(
            id: (string) $exercise->id,
            name: $exercise->name,
            is_custom: $exercise->user_id === $userId,
            video_url: ExercisesPayload::videoUrl($exercise->video_url),
            muscle_group: $exercise->muscle_group->value,
            muscle_group_text: $exercise->muscle_group->title(),
            equipment: $exercise->equipment->value,
            equipment_text: $exercise->equipment->title(),
            load_type: $exercise->load_type->value,
            load_type_text: $exercise->load_type->title(),
            rep_range_text: $inCurrentMesocycle ? "{$exercise->rep_range_min}–{$exercise->rep_range_max} reps" : null,
            pinned_note: $note,
            load_step: self::loadStep($exercise, $unit, $microPlates, $stepKg),
            history: self::history($exercise, $sets, $unit),
        );
    }

    /** EX-012: null for a reps-only exercise, which has no load to step. */
    private static function loadStep(Exercise $exercise, WeightUnit $unit, bool $microPlates, ?float $stepKg): ?ExerciseLoadStep
    {
        $default = TBL040LoadSteps::step($exercise->load_type->tbl040())->value($unit, $microPlates);
        if ($default === null) {
            return null;
        }

        $step = null;
        if ($stepKg !== null) {
            $value = round($unit === WeightUnit::Lb ? UnitConversion::kgToLb($stepKg) : $stepKg, 2);
            $step = new Measure($value, $unit->value, WorkoutWords::weight($value, $unit));
        }

        return new ExerciseLoadStep($step, WorkoutWords::weight($default, $unit), $unit->value);
    }

    /**
     * @param  Collection<int, WorkoutSet>  $sets
     * @return list<ExerciseHistoryDay>
     */
    private static function history(Exercise $exercise, Collection $sets, WeightUnit $unit): array
    {
        $loaded = $sets->filter(fn (WorkoutSet $set) => ($set->actual_weight_kg ?? 0) > 0)->pluck('workout_session_id')->unique()->all();

        return array_map(fn ($day) => new ExerciseHistoryDay(
            workout_id: $day->id,
            date_text: $day->date_label,
            // A day with no load has no best set: its sets would all estimate 0.
            sets: array_map(fn ($set) => new ExerciseHistorySet(
                $set->id,
                $set->label,
                $set->is_best && in_array($day->id, $loaded) ? new Pill('Best e1RM', Tone::Good->value) : null,
            ), $day->sets),
            best_text: in_array($day->id, $loaded) ? $day->best_label : null,
        ), ExerciseHistoryPayload::build($exercise, $sets, $unit)->sessions);
    }
}
