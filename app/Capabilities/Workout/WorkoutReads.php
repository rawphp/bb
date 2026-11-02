<?php

namespace App\Capabilities\Workout;

use App\Capabilities\Mesocycles\MesocycleReads;
use App\Capabilities\Support\ReadKey;
use App\Models\WorkoutSession;

/**
 * The read keys a feedback or finish write changes (CAP-017): the session's own workout_show, its mesocycle and Train.
 * workout_show is not registered yet (#83), so the writes cannot list it in `affects`; the client still drops its key.
 */
final class WorkoutReads
{
    /** @return list<string> */
    public static function keys(WorkoutSession $session): array
    {
        if ($session->mesocycle_id === null) {
            return [ReadKey::for('train_show')];
        }

        return [
            ReadKey::for('workout_show', ['mesocycle_id' => $session->mesocycle_id, 'week' => $session->week, 'day' => $session->day]),
            ...MesocycleReads::keys($session->mesocycle_id),
        ];
    }
}
