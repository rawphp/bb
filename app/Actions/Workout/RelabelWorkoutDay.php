<?php

namespace App\Actions\Workout;

use App\Capabilities\Support\FieldRefused;
use App\Domain\Mesocycle\DayEdits;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Support\Workout\MesocycleDay;
use App\Support\Workout\WorkoutSnapshot;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * WO-031, MESO-045 (rp-clone relabelDay): renames the session's day, trimmed, in every week of the mesocycle. It does
 * not start the session; a finished or skipped session refuses. The name the day already reads as changes nothing.
 *
 * Returns the session's row, or an unsaved one naming the session when it has none yet, for the reads it changed.
 */
final class RelabelWorkoutDay
{
    public const BLANK = 'Give the day a name.';

    public function __invoke(User $user, string $workoutId, string $label): WorkoutSession
    {
        $name = DayEdits::label($label) ?? throw new FieldRefused('label', self::BLANK);

        return DB::transaction(function () use ($user, $workoutId, $name) {
            $snapshot = WorkoutSnapshot::lockFor($user, $workoutId);
            $status = $snapshot->status();
            if (! $status->isEditable()) {
                throw new DomainException((string) $status->refusal());
            }
            MesocycleDay::label($snapshot->mesocycle, $snapshot->plan()->id, $name);

            return $snapshot->session() ?? new WorkoutSession(['mesocycle_id' => $snapshot->mesocycle->id, 'week' => $snapshot->week, 'day' => $snapshot->day]);
        });
    }
}
