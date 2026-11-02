<?php

namespace App\Actions\Workout;

use App\Domain\Model\ExerciseLoadType;
use App\Domain\Model\WeightUnit;
use App\Domain\Progression\SetLog;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Support\Workout\OpenWorkout;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * WO-003, WO-005, OFF-021: saves a set's weight and reps as the user typed them, and ticks or unticks Log. A logged
 * set counts as a working set (WO-025). The weight is stored in kg (CAP-015); one dumbbell's weight for dumbbells
 * (WO-023), the added weight for bodyweight, and 0 for a logged set with none.
 */
final class LogWorkoutSet
{
    public function __invoke(User $user, string $setId, ?float $weight, ?WeightUnit $unit, ?int $reps, bool $isLogged): WorkoutSession
    {
        return DB::transaction(function () use ($user, $setId, $weight, $unit, $reps, $isLogged) {
            $set = OpenWorkout::set($user, $setId);
            $loadType = $set->exercise->load_type ?? ExerciseLoadType::Barbell;
            $refusal = SetLog::refusal($isLogged, $set->is_skipped, $reps, $weight, $loadType);
            if ($refusal !== null) {
                throw new DomainException($refusal);
            }
            $set->update([
                'actual_weight_kg' => SetLog::kg($weight, $unit, $isLogged),
                'actual_reps' => $reps,
                'is_logged' => $isLogged,
            ]);

            return $set->session;
        });
    }
}
