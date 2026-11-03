<?php

namespace App\Support\Progress;

use App\Domain\Model\WeightUnit;
use App\Models\Exercise;
use App\Models\Profile;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What one exercise's history looks at (ANA-009, EX-002): the sets the user logged of it, in position order, whichever
 * template slot they came from. load() reads one page of up to PAGE_SIZE sessions, newest first by actual, else
 * planned start, the undated last, ties by session id descending; `before` names the last session of the previous
 * page. every() reads all of them. A set or session that is deleted is not in it.
 */
final class ExerciseHistorySnapshot
{
    public const PAGE_SIZE = 20;

    private const DATE = 'COALESCE(actual_start_at, planned_start_at)';

    /** @param  Collection<int, WorkoutSet>  $sets  each with its session loaded */
    public function __construct(
        public readonly Exercise $exercise,
        public readonly Collection $sets,
        public readonly WeightUnit $unit,
        public readonly ?string $nextBefore = null,
    ) {}

    /**
     * Null when the exercise is neither in the library nor the user's own.
     *
     * @param  string|null  $before  a session the user owns, already checked (CAP-020 step 2)
     */
    public static function load(User $user, string $exerciseId, ?string $before = null): ?self
    {
        $exercise = self::exercise($user, $exerciseId);
        if ($exercise === null) {
            return null;
        }

        $logged = self::logged($user, $exercise);
        $page = WorkoutSession::query()
            ->where('user_id', $user->id)
            ->whereHas('sets', $logged)
            ->when($before !== null, fn (Builder $sessions) => self::after($sessions, $user, (string) $before))
            ->orderByRaw(self::DATE.' IS NULL')
            ->orderByRaw(self::DATE.' DESC')
            ->orderByDesc('id')
            ->limit(self::PAGE_SIZE + 1)
            ->pluck('id')
            ->map(fn ($id) => (string) $id);
        $ids = $page->take(self::PAGE_SIZE)->all();

        return new self(
            exercise: $exercise,
            sets: $logged(WorkoutSet::query())
                ->whereIn('workout_session_id', $ids)
                ->with('session')
                ->orderBy('position')
                ->orderBy('id')
                ->get(),
            unit: self::unit($user),
            nextBefore: $page->count() > self::PAGE_SIZE ? end($ids) : null,
        );
    }

    /**
     * Every set the user logged of the exercise, in every session, unpaged: exercise_show's History tab (EX-002).
     * Null when the exercise is neither in the library nor the user's own.
     */
    public static function every(User $user, string $exerciseId): ?self
    {
        $exercise = self::exercise($user, $exerciseId);
        if ($exercise === null) {
            return null;
        }

        return new self(
            exercise: $exercise,
            sets: self::logged($user, $exercise)(WorkoutSet::query())
                ->whereHas('session', fn ($session) => $session->where('user_id', $user->id))
                ->with('session')
                ->orderBy('position')
                ->orderBy('id')
                ->get(),
            unit: self::unit($user),
        );
    }

    private static function exercise(User $user, string $exerciseId): ?Exercise
    {
        // A key like "12abc" would match exercise 12 in MySQL.
        if (preg_match('/^[1-9][0-9]*$/', $exerciseId) !== 1) {
            return null;
        }

        return Exercise::query()->visibleTo($user)->find((int) $exerciseId);
    }

    /** Narrows a sets query to the user's logged sets of the exercise. */
    private static function logged(User $user, Exercise $exercise): \Closure
    {
        return fn ($sets) => $sets->where('user_id', $user->id)->where('exercise_id', $exercise->id)->where('is_logged', true);
    }

    private static function unit(User $user): WeightUnit
    {
        return WeightUnit::tryFrom((string) Profile::query()->where('user_id', $user->id)->value('weight_unit')) ?? WeightUnit::Kg;
    }

    /**
     * Narrow to the sessions strictly after `before` in the history's order.
     *
     * @param  Builder<WorkoutSession>  $sessions
     * @return Builder<WorkoutSession>
     */
    private static function after(Builder $sessions, User $user, string $before): Builder
    {
        $cursor = WorkoutSession::query()->where('user_id', $user->id)->selectRaw('id, '.self::DATE.' AS sorted_at')->findOrFail($before);
        $at = $cursor->getRawOriginal('sorted_at');
        if ($at === null) {
            return $sessions->whereRaw(self::DATE.' IS NULL')->where('id', '<', $cursor->id);
        }

        return $sessions->where(fn (Builder $older) => $older
            ->whereRaw(self::DATE.' < ?', [$at])
            ->orWhere(fn (Builder $tied) => $tied->whereRaw(self::DATE.' = ?', [$at])->where('id', '<', $cursor->id))
            ->orWhereRaw(self::DATE.' IS NULL'));
    }
}
