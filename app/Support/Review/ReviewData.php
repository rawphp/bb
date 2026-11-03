<?php

namespace App\Support\Review;

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
use Illuminate\Support\Collection;

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
     * @param  list<array{id: string, date: string, checkInKcal: ?float}>  $meals  every meal of a target from `MEAL_WEEKS`
     *                                                                             weeks back (not before the plan start) to
     *                                                                             today, by its lower-case id and Y-m-d date,
     *                                                                             with the calories checked in
     * @param  Collection<int, WeighIn>  $weighIns  oldest first
     * @param  Collection<int, WeighInScheduleEntry>  $weighInSchedule
     * @param  Collection<int, WorkoutSession>  $sessions
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
        $plan = Plan::query()->where('user_id', $user->id)->first();
        $mealsFrom = $today->modify('-'.(7 * self::MEAL_WEEKS).' days');
        $planStart = $plan === null ? null : TrainSnapshot::day($plan->start_date);
        if ($planStart !== null && $planStart > $mealsFrom) {
            $mealsFrom = $planStart;
        }
        // The calorie rules read MEAL_WEEKS plan weeks up to the one that ended, which can start up to 6 days before
        // the meals do.
        $from = $mealsFrom->modify('-7 days')->format('Y-m-d');

        $dayTargets = DayTarget::query()->where('user_id', $user->id)->where('date', '>=', $from)->orderBy('date')->get();
        $sessions = WorkoutSession::query()->where('user_id', $user->id)->get();
        $sessionIds = $sessions->modelKeys();
        $sets = WorkoutSet::query()->where('user_id', $user->id)->whereIn('workout_session_id', $sessionIds)->orderBy('position')->get();
        $muscleFeedback = MuscleFeedback::query()->where('user_id', $user->id)->whereIn('workout_session_id', $sessionIds)->get();
        $exerciseFeedback = ExerciseFeedback::query()->where('user_id', $user->id)->whereIn('workout_session_id', $sessionIds)->get();
        $mesocycles = Mesocycle::query()->where('user_id', $user->id)->get();

        return new self(
            today: $today,
            plan: $plan,
            profile: Profile::query()->where('user_id', $user->id)->first(),
            phases: Phase::query()->where('user_id', $user->id)->orderBy('start_date')->get(),
            mesocycles: $mesocycles,
            planWeeks: PlanWeek::query()->where('user_id', $user->id)->get(),
            dayTargets: $dayTargets,
            otherActivities: OtherActivity::query()->where('user_id', $user->id)->where('date', '>=', $from)->get(),
            meals: self::meals($user, $dayTargets, $mealsFrom, $today),
            weighIns: WeighIn::query()->where('user_id', $user->id)->orderBy('weighed_at')->get(),
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
     * The meals of the targets from `$from` to `$to`, with what was checked in.
     *
     * @param  Collection<int, DayTarget>  $dayTargets
     * @return list<array{id: string, date: string, checkInKcal: ?float}>
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
