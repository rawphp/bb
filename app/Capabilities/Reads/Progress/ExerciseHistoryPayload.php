<?php

namespace App\Capabilities\Reads\Progress;

use App\Capabilities\Progress\Data\ExerciseHistorySession;
use App\Capabilities\Progress\Data\ExerciseHistorySet;
use App\Capabilities\Progress\Data\ExerciseHistoryShowOutput;
use App\Capabilities\Reads\Data\Today\Status;
use App\Capabilities\Support\Tone;
use App\Domain\Model\WeightUnit;
use App\Domain\Progress\EstimatedOneRepMax;
use App\Models\Exercise;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use App\Support\Plan\PlanWords;
use App\Support\Today\TodaySnapshot;
use Illuminate\Support\Collection;

/** Sessions of one exercise, newest first, the best e1RM of each marked (ANA-009, EX-002). */
final class ExerciseHistoryPayload
{
    /**
     * @param  Collection<int, WorkoutSet>  $sets  the logged sets in position order, each with its session loaded
     * @param  string|null  $nextBefore  the session to read older ones after, when there are any
     */
    public static function build(Exercise $exercise, Collection $sets, WeightUnit $unit, ?string $nextBefore = null): ExerciseHistoryShowOutput
    {
        $sessions = [];
        foreach ($sets->groupBy('workout_session_id') as $inSession) {
            /** @var WorkoutSession $session */
            $session = $inSession->first()->session;
            $date = TodaySnapshot::instant($session->actual_start_at ?? $session->planned_start_at);
            $estimates = $inSession->map(fn (WorkoutSet $set) => EstimatedOneRepMax::kg($set->actual_weight_kg, $set->actual_reps))->values()->all();
            $best = EstimatedOneRepMax::bestIndex($estimates);
            $sessions[] = [
                'time' => $date?->getTimestamp(),
                'row' => new ExerciseHistorySession(
                    id: (string) $session->id,
                    date_label: $date === null ? 'Session' : $date->format('D, j M Y'),
                    best_label: 'Best e1RM '.PlanWords::weight(EstimatedOneRepMax::best($estimates), $unit),
                    sets: $inSession->values()->map(fn (WorkoutSet $set, int $index) => new ExerciseHistorySet(
                        id: (string) $set->id,
                        label: self::label($set, $unit),
                        is_best: $index === $best,
                    ))->all(),
                ),
            ];
        }
        // Newest first, the undated last, ties by session id descending: the order ExerciseHistorySnapshot pages in.
        usort($sessions, fn (array $a, array $b) => (($b['time'] ?? PHP_INT_MIN) <=> ($a['time'] ?? PHP_INT_MIN)) ?: strcmp($b['row']->id, $a['row']->id));

        return new ExerciseHistoryShowOutput(
            title: $exercise->name,
            subtitle: $exercise->muscle_group->title(),
            best: new Status('Best e1RM', Tone::Good->value),
            sessions: array_column($sessions, 'row'),
            next_before: $nextBefore,
        );
    }

    /** "65.0 kg × 8"; a missing weight or missing reps is a dash. */
    private static function label(WorkoutSet $set, WeightUnit $unit): string
    {
        $weight = $set->actual_weight_kg === null ? '–' : PlanWords::weight($set->actual_weight_kg, $unit);

        return $weight.' × '.($set->actual_reps ?? '–');
    }
}
