<?php

namespace App\Capabilities\Reads;

use App\Capabilities\Exercises\Data\ExercisesShowInput;
use App\Capabilities\Exercises\Data\ExercisesShowOutput;
use App\Capabilities\HostCapability;
use App\Capabilities\Reads\Exercises\ExercisesPayload;
use App\Capabilities\Support\HostDefinition;
use App\Models\Exercise;
use App\Models\User;
use App\Models\WorkoutSet;
use App\Support\Today\TodaySnapshot;
use DateTimeImmutable;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class ExercisesShowCapability implements HostCapability
{
    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('exercises_show')
            ->description('List the exercise library: every exercise the user can pick, library and their own custom ones, sorted by name, each with its muscle group, equipment, whether it has a technique video and when it was last used, plus the muscle group, equipment and load type choices. Use it to find an exercise or its id. It changes nothing.')
            ->cli('exercise', 'list')
            ->input(ExercisesShowInput::class)
            ->output(ExercisesShowOutput::class)
            ->run(function (ExercisesShowInput $input, CapabilityContext $context): ExercisesShowOutput {
                /** @var User $user */
                $user = $context->user();

                return ExercisesPayload::build(Exercise::query()->visibleTo($user)->get(), $this->lastUsed($user));
            });
    }

    /**
     * EX-013: the newest session date of the user's logged sets of each exercise, in one query. The sets and sessions are
     * the ones ExerciseHistorySnapshot reads: the user's own, logged, and not deleted.
     *
     * @return array<int, DateTimeImmutable>
     */
    private function lastUsed(User $user): array
    {
        $newest = WorkoutSet::query()
            ->join('workout_sessions', 'workout_sessions.id', '=', 'workout_sets.workout_session_id')
            ->where('workout_sets.user_id', $user->id)
            ->where('workout_sets.is_logged', true)
            ->where('workout_sessions.user_id', $user->id)
            ->whereNull('workout_sessions.deleted_at')
            ->groupBy('workout_sets.exercise_id')
            ->selectRaw('workout_sets.exercise_id as exercise_id, max(coalesce(workout_sessions.actual_start_at, workout_sessions.planned_start_at)) as newest')
            ->toBase()
            ->get();

        $dates = [];
        foreach ($newest as $row) {
            $date = TodaySnapshot::instant($row->newest);
            if ($date !== null && $row->exercise_id !== null) {
                $dates[(int) $row->exercise_id] = $date;
            }
        }

        return $dates;
    }
}
