<?php

namespace App\Support\Review;

use App\Domain\Model\MealTarget;
use App\Domain\Progression\Underperformance;
use App\Models\DayTarget;
use App\Models\DayTargetMeal;
use App\Models\Exercise;
use App\Models\ExerciseFeedback;
use App\Models\Mesocycle;
use App\Models\MuscleFeedback;
use App\Models\OtherActivity;
use App\Models\Phase;
use App\Models\Plan;
use App\Models\PlanWeek;
use App\Models\Profile;
use App\Models\Step;
use App\Models\User;
use App\Models\WeighIn;
use App\Models\WeighInScheduleEntry;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use App\Support\Mesocycles\TrainSnapshot;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Everything the weekly review reads, loaded once for one user (rp-clone ReviewData and ReviewDataSource). Every
 * query is scoped to the user's own rows, or to rows hanging off the user's own sessions and meals; nothing is
 * written. Steps are the stored step rows (REV-050), where rp-clone read HealthKit.
 *
 * Dates are whole days at midnight UTC, read from their Y-m-d, as {@see TrainSnapshot} reads them.
 */
final readonly class ReviewData
{
    /** How many finished weeks of meals the calorie rules look back over (REV-021 reads the last 3 eligible). */
    public const MEAL_WEEKS = 12;

    /**
     * @param  Collection<int, Phase>  $phases  sorted by start
     * @param  Collection<int, Mesocycle>  $mesocycles  saved and drafts
     * @param  Collection<int, PlanWeek>  $planWeeks
     * @param  Collection<int, DayTarget>  $dayTargets  from a week before the oldest week the calorie rules read
     * @param  Collection<int, OtherActivity>  $otherActivities  over the same days
     * @param  list<array{id: string, date: string, checkInKcal: ?float, eaten: ?MealTarget}>  $meals  every meal of a target from `MEAL_WEEKS`
     *                                                                                                 weeks back (not before the plan start) to
     *                                                                                                 today, by its lower-case id and Y-m-d date,
     *                                                                                                 with the calories checked in
     * @param  Collection<int, WeighIn>  $weighIns  every one, oldest first: the trend weight (WT-010) runs from the
     *                                              first weigh-in, so a date bound would change it
     * @param  Collection<int, WeighInScheduleEntry>  $weighInSchedule
     * @param  Collection<int, WorkoutSession>  $sessions  those from the first week the review reads, and before it each
     *                                                     muscle's latest finished ones a PROG-050 run needs
     * @param  Collection<int, WorkoutSet>  $sets  of those sessions, by position
     * @param  Collection<int, Exercise>  $exercises  the ones the sets, the feedback and the mesocycles name, keyed by id
     * @param  Collection<int, MuscleFeedback>  $muscleFeedback
     * @param  Collection<int, ExerciseFeedback>  $exerciseFeedback
     * @param  Collection<int, Step>  $steps  over the same days as the targets
     */
    public function __construct(
        public DateTimeImmutable $today,
        public ?Plan $plan,
        public ?Profile $profile,
        public Collection $phases,
        public Collection $mesocycles,
        public Collection $planWeeks,
        public Collection $dayTargets,
        public Collection $otherActivities,
        public array $meals,
        public Collection $weighIns,
        public Collection $weighInSchedule,
        public Collection $sessions,
        public Collection $sets,
        public Collection $exercises,
        public Collection $muscleFeedback,
        public Collection $exerciseFeedback,
        public Collection $steps,
    ) {}

    public static function load(User $user, DateTimeImmutable|string $today): self
    {
        $today = TrainSnapshot::day($today);
        $plan = Plan::query()->where('user_id', $user->id)->with('reviewDayChanges')->first();
        $mealsFrom = $today->modify('-'.(7 * self::MEAL_WEEKS).' days');
        $planStart = $plan === null ? null : TrainSnapshot::day($plan->start_date);
        if ($planStart !== null && $planStart > $mealsFrom) {
            $mealsFrom = $planStart;
        }
        // The calorie rules read MEAL_WEEKS plan weeks up to the one that ended, which can start up to 6 days before
        // the meals do.
        $from = $mealsFrom->modify('-7 days')->format('Y-m-d');

        $dayTargets = DayTarget::query()->where('user_id', $user->id)->where('date', '>=', $from)->orderBy('date')->get();
        $phases = Phase::query()->where('user_id', $user->id)->orderBy('start_date')->get();
        $windowStart = self::sessionWindowStart($phases, $from);
        $lookBack = self::latestTrainingSessionsBefore($user, $windowStart);
        $sessions = WorkoutSession::query()->where('user_id', $user->id)
            ->where(fn (Builder $q) => $q
                ->whereRaw('COALESCE(actual_start_at, planned_start_at) >= ?', [$windowStart])
                ->orWhere(fn (Builder $none) => $none->whereNull('actual_start_at')->whereNull('planned_start_at'))
                ->orWhereIn('id', $lookBack))
            ->get();
        $sessionIds = $sessions->modelKeys();
        $sets = WorkoutSet::query()->where('user_id', $user->id)->whereIn('workout_session_id', $sessionIds)->orderBy('position')->get();
        $muscleFeedback = MuscleFeedback::query()->where('user_id', $user->id)->whereIn('workout_session_id', $sessionIds)->get();
        $exerciseFeedback = ExerciseFeedback::query()->where('user_id', $user->id)->whereIn('workout_session_id', $sessionIds)->get();
        $mesocycles = Mesocycle::query()->where('user_id', $user->id)->get();

        return new self(
            today: $today,
            plan: $plan,
            profile: Profile::query()->where('user_id', $user->id)->first(),
            phases: $phases,
            mesocycles: $mesocycles,
            planWeeks: PlanWeek::query()->where('user_id', $user->id)->get(),
            dayTargets: $dayTargets,
            otherActivities: OtherActivity::query()->where('user_id', $user->id)->where('date', '>=', $from)->get(),
            meals: self::meals($user, $dayTargets, $mealsFrom, $today),
            weighIns: WeighIn::query()->where('user_id', $user->id)->orderBy('weighed_at')->get(['id', 'user_id', 'weight_kg', 'weighed_at']),
            weighInSchedule: WeighInScheduleEntry::query()->where('user_id', $user->id)->orderBy('id')->get(),
            sessions: $sessions,
            sets: $sets,
            exercises: self::exercises($user, $sets, $exerciseFeedback, $mesocycles),
            muscleFeedback: $muscleFeedback,
            exerciseFeedback: $exerciseFeedback,
            steps: Step::query()->where('user_id', $user->id)->where('date', '>=', $from)->get(),
        );
    }

    /**
     * The first day whose sessions the review reads: the oldest of the calorie weeks (from `$from`) and the plan week
     * that holds the start of any phase still running at `$from`, whose weeks the fatigue rules read (REV-040). A
     * day's slack covers a session's time of day.
     *
     * @param  Collection<int, Phase>  $phases
     */
    private static function sessionWindowStart(Collection $phases, string $from): string
    {
        $start = TrainSnapshot::day($from);
        foreach ($phases as $phase) {
            if ($phase->end_date !== null && TrainSnapshot::day($phase->end_date) < $start) {
                continue;
            }
            $weekStart = TrainSnapshot::day($phase->start_date)->modify('-7 days');
            if ($weekStart < $start) {
                $start = $weekStart;
            }
        }

        return $start->modify('-1 day')->format('Y-m-d 00:00:00');
    }

    /**
     * PROG-050 before the window: for each muscle, the latest finished sessions that trained it (a logged set with its
     * target and reps, of an exercise the user can see), as many as a run needs. A run that ends inside the window
     * reads nothing older, so the flags come out as they would from the whole history.
     *
     * @return list<string> the sessions' ids
     */
    private static function latestTrainingSessionsBefore(User $user, string $windowStart): array
    {
        $trained = DB::table('workout_sessions as s')
            ->join('workout_sets as ws', 'ws.workout_session_id', '=', 's.id')
            ->join('exercises as e', 'e.id', '=', 'ws.exercise_id')
            ->where('s.user_id', $user->id)
            ->where('ws.user_id', $user->id)
            ->where('s.is_skipped', false)
            ->whereNotNull('s.actual_start_at')
            ->whereNotNull('s.actual_end_at')
            ->where('s.actual_start_at', '<', $windowStart)
            ->where('ws.is_logged', true)
            ->where('ws.is_skipped', false)
            ->whereNotNull('ws.target_reps')
            ->whereNotNull('ws.actual_reps')
            ->where(fn ($q) => $q->whereNull('e.user_id')->orWhere('e.user_id', $user->id))
            ->distinct()
            ->select(['s.id', 'e.muscle_group', 's.actual_start_at']);
        $ranked = DB::query()->fromSub($trained, 't')->select('t.id')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY t.muscle_group ORDER BY t.actual_start_at DESC, t.id DESC) AS position');

        return DB::query()->fromSub($ranked, 'r')->where('r.position', '<=', Underperformance::SESSIONS_RUNNING)
            ->distinct()->pluck('r.id')->map(fn ($id) => (string) $id)->values()->all();
    }

    /**
     * The meals of the targets from `$from` to `$to`, with what was checked in.
     *
     * @param  Collection<int, DayTarget>  $dayTargets
     * @return list<array{id: string, date: string, checkInKcal: ?float, eaten: ?MealTarget}>
     */
    private static function meals(User $user, Collection $dayTargets, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $dates = [];
        foreach ($dayTargets as $target) {
            $day = TrainSnapshot::day($target->date);
            if ($day >= $from && $day <= $to) {
                $dates[$target->id] = $day->format('Y-m-d');
            }
        }
        $rows = DayTargetMeal::query()->where('user_id', $user->id)->whereIn('day_target_id', array_keys($dates))
            ->with('checkIn')->orderBy('position')->get();

        return $rows->map(fn (DayTargetMeal $meal) => [
            'id' => strtolower((string) $meal->id),
            'date' => $dates[$meal->day_target_id],
            'checkInKcal' => $meal->checkIn?->kcal,
            'eaten' => $meal->checkIn === null ? null : new MealTarget($meal->checkIn->kcal, $meal->checkIn->protein_g, $meal->checkIn->fat_g, $meal->checkIn->carbs_g),
        ])->values()->all();
    }

    /**
     * The exercises the sets, the joint-pain answers and the mesocycles' slots name, as the user can see them.
     *
     * @param  Collection<int, WorkoutSet>  $sets
     * @param  Collection<int, ExerciseFeedback>  $feedback
     * @param  Collection<int, Mesocycle>  $mesocycles
     * @return Collection<int, Exercise>
     */
    private static function exercises(User $user, Collection $sets, Collection $feedback, Collection $mesocycles): Collection
    {
        $ids = [...$sets->pluck('exercise_id')->all(), ...$feedback->pluck('exercise_id')->all()];
        foreach ($mesocycles as $mesocycle) {
            foreach ([$mesocycle->dayRows(), ...$mesocycle->earlierWeekRows()] as $days) {
                foreach ($days as $day) {
                    $ids = [...$ids, ...array_column($day['slots'], 'exercise_id')];
                }
            }
        }
        $ids = array_values(array_unique(array_filter($ids, fn ($id) => $id !== null)));

        return Exercise::query()->visibleTo($user)->whereIn('id', $ids)->get()->keyBy('id');
    }
}
