<?php

namespace App\Actions\Workout;

use App\Actions\Today\RetargetableDay;
use App\Domain\Progression\WorkoutStatus;
use App\Domain\Retarget\MealRetarget;
use App\Domain\Retarget\Retargeting;
use App\Domain\WeekBuilder\AwayWeek\Training;
use App\Models\PlanWeek;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Support\Today\DayPlans;
use App\Support\Today\TodaySnapshot;
use App\Support\Workout\WorkoutSnapshot;
use DateTimeImmutable;
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

            // The day Today shows it on: where it started, else where it was moved, else where Today places it.
            $at = $row->actual_start_at ?? $row?->planned_start_at;
            $date = $at !== null ? DayPlans::day($at) : self::shownOn($user, $snapshot);
            $retarget = $date === null ? null : self::retarget($user, $date->format('Y-m-d'), $snapshot);

            if ($row === null) {
                // Saved on the day Today showed it, so the skipped session stays there (SCH-051).
                $shift = $date === null || $snapshot->date() === null ? 0 : (int) $snapshot->date()->diff($date)->format('%r%a');
                $row = (new WorkoutSession([
                    'mesocycle_id' => $snapshot->mesocycle->id,
                    'week' => $snapshot->week,
                    'day' => $snapshot->day,
                    'planned_start_at' => $snapshot->plannedStart()?->modify("{$shift} days"),
                    'planned_end_at' => $snapshot->plannedEnd()?->modify("{$shift} days"),
                ]))->forceFill(['user_id' => $user->id]);
            }
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
     * SCH-060: the day Today shows a session with no row on. The plan's date does not count away weeks paused before it,
     * and each moves the mesocycle a plan week on, so it is the plan's date or one of the weeks after it, as many as are
     * paused; null when Today shows it on none of them (a later mesocycle took the week).
     */
    private static function shownOn(User $user, WorkoutSnapshot $session): ?DateTimeImmutable
    {
        $planned = $session->date();
        if ($planned === null) {
            return null;
        }
        $paused = PlanWeek::query()->where('user_id', $user->id)->where('is_away', true)->where('away_training', Training::Pause->value)->count();
        for ($weeks = 0; $weeks <= $paused; $weeks++) {
            $date = DayPlans::day($planned->modify("+{$weeks} weeks"));
            if (self::shows(TodaySnapshot::load($user, $date->format('Y-m-d')), $date, $session)) {
                return $date;
            }
        }

        return null;
    }

    private static function shows(TodaySnapshot $today, DateTimeImmutable $date, WorkoutSnapshot $session): bool
    {
        $shown = $today->workout($date);

        return $shown !== null && (string) $shown->mesocycle->id === (string) $session->mesocycle->id
            && $shown->week === $session->week && $shown->day === $session->day;
    }

    /**
     * SCH-053 on `$date`, read before the session is saved skipped; null when there is nothing to re-target, and the
     * skip goes through without it: a past day (its targets stay, as SCH-057's skip leaves them), a day with no meals
     * planned, a user the re-target rule cannot work for yet (no eating window or weight), or a day Today shows no
     * session on. Retargeting::skip refuses nothing else. A day Today gives to another session is left alone too.
     *
     * @return ?array{RetargetableDay, MealRetarget}
     */
    private static function retarget(User $user, string $date, WorkoutSnapshot $session): ?array
    {
        try {
            $day = RetargetableDay::load($user, $date);
        } catch (DomainException) {
            return null;
        }
        if (! self::shows($day->snapshot, DayPlans::day($date), $session)) {
            return null;
        }
        try {
            return [$day, Retargeting::skip($day->day, $day->context)];
        } catch (DomainException) {
            return null;
        }
    }
}
