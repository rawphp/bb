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
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The rows the session actions change (WO-022, WO-031, EX-008), in a session under way. Each action finds its
 * mesocycle before its transaction ({@see mesocycleOf()}), and locking it is the transaction's first statement
 * ({@see lock()}), as every workout write that reads the whole session locks it ({@see WorkoutSnapshot::lockFor()}).
 * One structure change then runs at a time per mesocycle, and every read after the lock sees the latest commit: MySQL's
 * REPEATABLE READ fixes a transaction's view at its first plain read (docs/architecture.md, Server), and there is none
 * before the lock. Rows the action changes are read with a lock too, as other writes to them do not take the mesocycle's.
 */
final class SessionBlocks
{
    /** The mesocycle of the user's exercise `$id`, removed or not; read before the write's transaction, as it never changes. */
    public static function mesocycleOf(User $user, string $id): ?string
    {
        $block = WorkoutExercise::query()->withTrashed()->where('user_id', $user->id)->whereKey($id)->first();
        $mesocycleId = $block === null ? null : WorkoutSession::query()->withTrashed()->whereKey($block->workout_session_id)->value('mesocycle_id');

        return $mesocycleId === null ? null : (string) $mesocycleId;
    }

    /** Locks the mesocycle: the first statement of a session action's transaction. */
    public static function lock(?string $mesocycleId): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('SessionBlocks::lock() takes a lock that lasts until a transaction ends; call it inside one.');
        }
        if ($mesocycleId !== null) {
            Mesocycle::query()->whereKey($mesocycleId)->lockForUpdate()->first();
        }
    }

    /**
     * The user's exercise of a session under way, locked with its session. A session not started, finished or skipped
     * refuses in plain words (rp-clone isReadOnly).
     *
     * @throws ModelNotFoundException<WorkoutExercise>
     */
    public static function block(User $user, string $id): WorkoutExercise
    {
        $block = WorkoutExercise::query()->where('user_id', $user->id)->whereKey($id)->lockForUpdate()->firstOrFail();
        $session = WorkoutSession::query()->whereKey($block->workout_session_id)->lockForUpdate()->first()
            ?? throw (new ModelNotFoundException)->setModel(WorkoutSession::class);
        $refusal = $session->status()->refusal();
        if ($refusal !== null) {
            throw new DomainException($refusal);
        }

        return $block->setRelation('session', $session);
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
     * The user's exercise under `$id`, removed ones too: a create's ULID is spent once used (CAP-019). Read after the
     * mesocycle lock, so a request sent again that waited on the first sees what it made. A plain read: a locking read
     * of an id not there yet would take a gap lock, on which two creates in different mesocycles deadlock.
     */
    public static function made(User $user, string $id): ?WorkoutExercise
    {
        return WorkoutExercise::query()->withTrashed()->where('user_id', $user->id)->whereKey(strtolower($id))->first();
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
