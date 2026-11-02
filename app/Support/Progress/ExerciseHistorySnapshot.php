<?php

namespace App\Support\Progress;

use App\Domain\Model\WeightUnit;
use App\Models\Exercise;
use App\Models\Profile;
use App\Models\User;
use App\Models\WorkoutSet;
use Illuminate\Support\Collection;

/**
 * What one exercise's history looks at (ANA-009, EX-002): every set the user logged of it, in position order, whichever
 * template slot it came from. A set or session that is deleted is not in it.
 */
final class ExerciseHistorySnapshot
{
    /** @param  Collection<int, WorkoutSet>  $sets  each with its session loaded */
    public function __construct(
        public readonly Exercise $exercise,
        public readonly Collection $sets,
        public readonly WeightUnit $unit,
    ) {}

    /** Null when the exercise is neither in the library nor the user's own. */
    public static function load(User $user, string $exerciseId): ?self
    {
        // A key like "12abc" would match exercise 12 in MySQL.
        if (preg_match('/^[1-9][0-9]*$/', $exerciseId) !== 1) {
            return null;
        }
        $exercise = Exercise::query()->visibleTo($user)->find((int) $exerciseId);
        if ($exercise === null) {
            return null;
        }

        return new self(
            exercise: $exercise,
            sets: WorkoutSet::query()
                ->where('user_id', $user->id)
                ->where('exercise_id', $exercise->id)
                ->where('is_logged', true)
                ->whereHas('session', fn ($session) => $session->where('user_id', $user->id))
                ->with('session')
                ->orderBy('position')
                ->orderBy('id')
                ->get(),
            unit: WeightUnit::tryFrom((string) Profile::query()->where('user_id', $user->id)->value('weight_unit')) ?? WeightUnit::Kg,
        );
    }
}
