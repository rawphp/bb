<?php

namespace App\Support\Workout;

use App\Models\Mesocycle;
use App\Models\User;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The rows the session actions change (WO-022, WO-031, EX-008), in a session under way. The mesocycle row is locked
 * first, as every workout write that reads the whole session does ({@see WorkoutSnapshot::lockFor()}), so one structure
 * change runs at a time per mesocycle. Every row after the lock is read with a lock: a plain read inside the
 * transaction would see the first view it took, from before the lock (docs/architecture.md, Server). Call it inside the
 * write's transaction.
 */
final class SessionBlocks
{
    /**
     * The user's exercise of a session under way, locked with its session. A session not started, finished or skipped
     * refuses in plain words (rp-clone isReadOnly).
     *
     * @throws ModelNotFoundException<WorkoutExercise>
     */
    public static function block(User $user, string $id): WorkoutExercise
    {
        self::lock($user, $id);
        $block = WorkoutExercise::query()->where('user_id', $user->id)->whereKey($id)->lockForUpdate()->firstOrFail();
        $session = WorkoutSession::query()->whereKey($block->workout_session_id)->lockForUpdate()->first()
            ?? throw (new ModelNotFoundException)->setModel(WorkoutSession::class);
        $refusal = $session->status()->refusal();
        if ($refusal !== null) {
            throw new DomainException($refusal);
        }

        return $block->setRelation('session', $session);
    }

    /** Locks the mesocycle of the user's exercise `$id`, removed or not. Its session's mesocycle never changes, so it is found before the lock. */
    public static function lock(User $user, string $id): void
    {
        $block = WorkoutExercise::query()->withTrashed()->where('user_id', $user->id)->whereKey($id)->first();
        $mesocycleId = $block === null ? null : WorkoutSession::query()->withTrashed()->whereKey($block->workout_session_id)->value('mesocycle_id');
        if ($mesocycleId !== null) {
            Mesocycle::query()->whereKey($mesocycleId)->lockForUpdate()->first();
        }
    }

    /** @return list<WorkoutExercise> the session's exercises in order, locked */
    public static function blocks(WorkoutSession $session): array
    {
        return WorkoutExercise::query()->where('workout_session_id', $session->id)->orderBy('position')->lockForUpdate()->get()->all();
    }

    /** @return list<WorkoutSet> the exercise's sets in order, locked */
    public static function sets(WorkoutExercise $block): array
    {
        return WorkoutSet::query()->where('workout_exercise_id', $block->id)->orderBy('position')->lockForUpdate()->get()->all();
    }

    /**
     * The user's exercise under `$id`, removed ones too: a create's ULID is spent once used (CAP-019). A plain read: a
     * locking read of an id not there yet takes a gap lock, and two creates in different mesocycles would then deadlock
     * on their inserts. It can miss a request that committed while this one waited on the mesocycle lock; the insert's
     * primary key refuses that one ({@see create()}). `$locked` reads the latest commit, for when the row is known to be
     * there or nothing is inserted after.
     */
    public static function made(User $user, string $id, bool $locked = false): ?WorkoutExercise
    {
        $query = WorkoutExercise::query()->withTrashed()->where('user_id', $user->id)->whereKey(strtolower($id));

        return ($locked ? $query->sharedLock() : $query)->first();
    }

    /** Saves a new exercise; false when its ULID is already taken, by a request that committed first (a replay). */
    public static function create(WorkoutExercise $block): bool
    {
        try {
            $block->save();

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    /** @param  list<WorkoutExercise>  $blocks */
    public static function indexOf(array $blocks, WorkoutExercise $block): int
    {
        foreach ($blocks as $index => $candidate) {
            if ($candidate->is($block)) {
                return $index;
            }
        }

        throw (new ModelNotFoundException)->setModel(WorkoutExercise::class, [$block->id]);
    }

    /** @param  list<WorkoutExercise|WorkoutSet>  $rows  positions 1, 2, … in this order */
    public static function renumber(array $rows): void
    {
        foreach ($rows as $index => $row) {
            /** @var Model $row */
            if ($row->getAttribute('position') !== $index + 1) {
                $row->update(['position' => $index + 1]);
            }
        }
    }

    /** The session read whole, with locks: what a change written to its mesocycle's day needs. */
    public static function snapshot(User $user, WorkoutSession $session): WorkoutSnapshot
    {
        return WorkoutSnapshot::lockFor($user, WorkoutIds::workout((string) $session->mesocycle_id, $session->week, $session->day));
    }
}
