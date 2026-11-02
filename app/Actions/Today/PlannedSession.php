<?php

namespace App\Actions\Today;

use App\Domain\Placement\ScheduledSession;
use App\Domain\Placement\WorkoutTime;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Support\Mesocycles\TrainSnapshot;
use App\Support\Today\DayPlans;
use App\Support\Today\TodaySnapshot;
use App\Support\Today\TodayWorkoutItem;
use App\Support\Workout\WorkoutSnapshot;
use DateTimeImmutable;
use DomainException;

/**
 * A mesocycle session as the Today writes change it (SCH-038, SCH-057): found on the day its stored row or the plan
 * puts it, as Today shows it there, and its row placed on a day at a clock time until its length.
 */
final readonly class PlannedSession
{
    public const NOT_FOUND = 'That workout isn\'t on your plan. Nothing was changed.';

    /** SCH-038: what TodaySnapshot::isMovable() refuses. */
    public const LOCKED = 'That workout has started, is done or was skipped, or its day has passed, so it can\'t change.';

    private function __construct(
        /** Today around the session's day. */
        public TodaySnapshot $snapshot,
        public TodayWorkoutItem $item,
        public ScheduledSession $scheduled,
        public ?WorkoutSession $row,
    ) {}

    /**
     * Session `$day` (from 1) of mesocycle week `$week` (from 1), when it can still be moved or re-timed.
     *
     * @throws DomainException when Today shows it on no day, or it can no longer change
     */
    public static function movable(User $user, string $mesocycleId, int $week, int $day): self
    {
        $train = TrainSnapshot::load($user, $mesocycleId);
        $mesocycle = $train->mesocycle($mesocycleId);
        $row = $train->sessions->first(fn (WorkoutSession $session) => $session->mesocycle_id === $mesocycle->id
            && $session->week === $week && $session->day === $day);
        // A row holds its day as the plan week reads it (SessionRow): the start, else where it was moved; otherwise the plan does.
        $at = $row?->actual_start_at ?? $row?->planned_start_at;
        $date = $at !== null ? DayPlans::day($at) : WorkoutSnapshot::dateOf($train, $mesocycle, $week, $day);
        $snapshot = $date === null ? null : TodaySnapshot::load($user, $date->format('Y-m-d'));
        $item = $snapshot?->workout($date);
        $scheduled = $snapshot?->planning($date)['plan']->session ?? null;
        if ($item === null || $scheduled === null || $item->mesocycle->id !== $mesocycle->id || $item->week !== $week || $item->day !== $day) {
            throw new DomainException(self::NOT_FOUND);
        }
        if (! $snapshot->isMovable($item)) {
            throw new DomainException(self::LOCKED);
        }

        return new self($snapshot, $item, $scheduled, $row);
    }

    /** The session's row, placed on `$day` at `$time` until its length; a stored row keeps its id. */
    public static function saveRow(User $user, ?WorkoutSession $stored, string $mesocycleId, int $week, int $day, DateTimeImmutable $date, WorkoutTime $time, bool $skipped = false): void
    {
        $start = DayPlans::day($date)->modify("+{$time->start->minuteOfDay} minutes");
        $row = $stored ?? new WorkoutSession;
        $row->forceFill([
            'user_id' => $user->id,
            'mesocycle_id' => $mesocycleId,
            'week' => $week,
            'day' => $day,
            'planned_start_at' => $start,
            'planned_end_at' => $start->modify("+{$time->lengthMinutes} minutes"),
            'is_skipped' => $skipped,
        ])->save();
    }
}
