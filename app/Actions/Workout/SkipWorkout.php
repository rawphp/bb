<?php

namespace App\Actions\Workout;

use App\Actions\Today\RetargetableDay;
use App\Domain\Progression\WorkoutStatus;
use App\Domain\Retarget\MealRetarget;
use App\Domain\Retarget\Retargeting;
use App\Domain\Retarget\RetargetRefusal;
use App\Domain\Retarget\RetargetRefused;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Support\Today\DayPlans;
use App\Support\Workout\WorkoutSnapshot;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * WO-050 (rp-clone skipWorkout): marks a session skipped. One not started is saved skipped with no sets, on the row
 * that moved it if there is one (SCH-051); one under way keeps what was logged. A finished session refuses, and one
 * already skipped stays as it is. SCH-053: the day Today shows it on, from today on, becomes a rest day and its
 * unchecked meals are re-targeted; a past day keeps its targets. The view asks first: it cannot be undone.
 */
final class SkipWorkout
{
    /** @return array{session: WorkoutSession, toast: ?string, dates: list<string>} the row, and the day re-targeted with its toast */
    public function __invoke(User $user, string $workoutId): array
    {
        return DB::transaction(function () use ($user, $workoutId) {
            $snapshot = WorkoutSnapshot::lockFor($user, $workoutId);
            $status = $snapshot->status();
            $row = $snapshot->session();
            if ($row !== null && $status === WorkoutStatus::Skipped) {
                return ['session' => $row, 'toast' => null, 'dates' => []];
            }
            if (! $status->isEditable()) {
                throw new DomainException((string) $status->refusal());
            }

            // The day Today shows it on: where it started, else where it was moved, else where the plan puts it.
            $at = $row->actual_start_at ?? $row?->planned_start_at;
            $date = $at !== null ? DayPlans::day($at) : $snapshot->date();
            $retarget = $date === null ? null : self::retarget($user, $date->format('Y-m-d'));

            $row ??= (new WorkoutSession([
                'mesocycle_id' => $snapshot->mesocycle->id,
                'week' => $snapshot->week,
                'day' => $snapshot->day,
                'planned_start_at' => $snapshot->plannedStart(),
                'planned_end_at' => $snapshot->plannedEnd(),
            ]))->forceFill(['user_id' => $user->id]);
            $row->fill(['is_skipped' => true])->save();
            if ($retarget === null) {
                return ['session' => $row, 'toast' => null, 'dates' => []];
            }
            [$day, $result] = $retarget;
            $day->save($result->days[0]);

            return ['session' => $row, 'toast' => $result->message, 'dates' => [$day->date]];
        });
    }

    /**
     * SCH-053 on `$date`, read before the session is saved skipped; null when there is nothing to re-target: a past
     * day, a day with no meals or no targets yet, or one Today shows no session on.
     *
     * @return ?array{RetargetableDay, MealRetarget}
     */
    private static function retarget(User $user, string $date): ?array
    {
        try {
            $day = RetargetableDay::load($user, $date);
        } catch (DomainException) {
            return null;
        }
        try {
            return [$day, Retargeting::skip($day->day, $day->context)];
        } catch (RetargetRefused $refused) {
            if ($refused->refusal === RetargetRefusal::NoSession) {
                return null;
            }
            throw RetargetableDay::refused($refused);
        }
    }
}
