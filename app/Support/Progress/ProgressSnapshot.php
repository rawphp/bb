<?php

namespace App\Support\Progress;

use App\Domain\Model\HeightUnit;
use App\Domain\Model\Phase as DomainPhase;
use App\Domain\Model\StepPlanDay;
use App\Domain\Model\WeighIn as DomainWeighIn;
use App\Domain\Model\WeightUnit;
use App\Domain\Progress\LoggedWorkingSet;
use App\Domain\Progress\ProgressMesocycle;
use App\Domain\Progression\WorkingSet;
use App\Domain\Weight\TrendWeight;
use App\Models\Exercise;
use App\Models\Mesocycle;
use App\Models\Phase;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\Step;
use App\Models\User;
use App\Models\WeighIn;
use App\Models\WorkoutSet;
use App\Support\Mesocycles\TrainSnapshot;
use App\Support\Plan\PlanSnapshot;
use App\Support\Today\TodaySnapshot;
use App\Support\UserDay;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Collection;
use stdClass;

/**
 * What the Progress tab looks at, read once per invoke (ANA-001 to ANA-011): the plan, phases, mesocycles and week
 * as {@see TodaySnapshot} reads them, every weigh-in, today's steps, the sets of live mesocycle sessions, and when each exercise was last used.
 *
 * Dates are whole days at midnight UTC, read from their Y-m-d. A weigh-in's day is the user's local day (SET-012):
 * {@see weighedAt()} reads its stored instant as the user's clock time.
 */
final class ProgressSnapshot
{
    private ?TrendWeight $trend = null;

    /**
     * @param  TodaySnapshot  $week  the plan, profile, phases, mesocycles and plan weeks, and the week around today with
     *                               its meals' planned foods and recipes, which Calories this week counts (DAY-011)
     * @param  Collection<int, WeighIn>  $weighIns  all of them
     * @param  ?int  $steps  today's step count; null when none is stored
     * @param  Collection<int, stdClass>  $sets  the working sets (WO-025) of live mesocycle sessions, one row (mesocycle_id, week, exercise_id, sets) per mesocycle week and exercise
     * @param  Collection<int, Exercise>  $exercises  the visible exercises that occur in `$sets` or `$lastUsed`
     * @param  array<int, string>  $lastUsed  exercise id => start of the latest session with a logged set of it
     */
    public function __construct(
        public readonly TodaySnapshot $week,
        public readonly Collection $weighIns,
        public readonly ?int $steps,
        public readonly Collection $sets,
        public readonly Collection $exercises,
        public readonly array $lastUsed,
    ) {}

    public static function load(User $user): self
    {
        $week = TodaySnapshot::load($user);
        $sets = self::sets($user);
        $lastUsed = self::lastUsed($user);
        $steps = Step::query()->where('user_id', $user->id)->whereDate('date', $week->today()->format('Y-m-d'))->value('count');

        return new self(
            week: $week,
            weighIns: WeighIn::query()->where('user_id', $user->id)->orderBy('weighed_at')->get(['id', 'weight_kg', 'waist_cm', 'weighed_at']),
            steps: $steps === null ? null : (int) $steps,
            sets: $sets,
            exercises: Exercise::query()->visibleTo($user)
                ->whereIn('id', $sets->pluck('exercise_id')->merge(array_keys($lastUsed))->unique()->values()->all())
                ->get(['id', 'name', 'muscle_group']),
            lastUsed: $lastUsed,
        );
    }

    /**
     * ANA-007: the user's working sets of live sessions in a mesocycle, counted per mesocycle, week and exercise in one
     * grouped read, so the rows grow with mesocycle weeks and exercises, not with every set ever logged. A working set
     * is logged and not skipped (WO-025, {@see WorkingSet::counts()}).
     *
     * @return Collection<int, stdClass>
     */
    private static function sets(User $user): Collection
    {
        return WorkoutSet::query()
            ->join('workout_sessions as s', 's.id', '=', 'workout_sets.workout_session_id')
            ->where('workout_sets.user_id', $user->id)
            ->where('workout_sets.is_logged', true)
            ->where('workout_sets.is_skipped', false)
            ->whereNotNull('workout_sets.exercise_id')
            ->where('s.user_id', $user->id)
            ->whereNotNull('s.mesocycle_id')
            ->whereNull('s.deleted_at')
            ->groupBy('s.mesocycle_id', 's.week', 'workout_sets.exercise_id')
            ->selectRaw('s.mesocycle_id as mesocycle_id, s.week as week, workout_sets.exercise_id as exercise_id, count(*) as sets')
            ->toBase()
            ->get();
    }

    /**
     * EX-013: the latest start (actual, else planned) of the user's sessions that are not deleted and hold a logged
     * set of each exercise. An exercise with no such date is left out.
     *
     * @return array<int, string>
     */
    private static function lastUsed(User $user): array
    {
        $rows = WorkoutSet::query()
            ->join('workout_sessions as s', 's.id', '=', 'workout_sets.workout_session_id')
            ->where('workout_sets.user_id', $user->id)
            ->where('workout_sets.is_logged', true)
            ->whereNotNull('workout_sets.exercise_id')
            ->where('s.user_id', $user->id)
            ->whereNull('s.deleted_at')
            ->groupBy('workout_sets.exercise_id')
            ->selectRaw('workout_sets.exercise_id as exercise_id, max(coalesce(s.actual_start_at, s.planned_start_at)) as last_used')
            ->toBase()
            ->get();

        $lastUsed = [];
        foreach ($rows as $row) {
            if ($row->last_used !== null) {
                $lastUsed[(int) $row->exercise_id] = (string) $row->last_used;
            }
        }

        return $lastUsed;
    }

    public function today(): DateTimeImmutable
    {
        return $this->week->today();
    }

    /**
     * The calendar-day zone: UTC, by decision 5 of #229, as every day here is midnight UTC of its Y-m-d. Weigh-ins come
     * in as the user's clock times ({@see weighedAt()}), so this stays UTC.
     */
    public function zone(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }

    public function plan(): ?Plan
    {
        return $this->week->train->plan;
    }

    public function weightUnit(): WeightUnit
    {
        return WeightUnit::tryFrom((string) ($this->week->train->profile->weight_unit ?? '')) ?? WeightUnit::Kg;
    }

    /** The waist is shown in centimetres unless the user's height unit is feet and inches. */
    public function usesCentimetres(): bool
    {
        return ($this->week->train->profile->height_unit ?? HeightUnit::Cm->value) === HeightUnit::Cm->value;
    }

    /** @return list<StepPlanDay> */
    public function stepPlan(): array
    {
        return collect(TodaySnapshot::json($this->week->train->profile?->step_plan))
            ->map(fn (array $day) => new StepPlanDay((int) $day['weekday'], (int) $day['min'], (int) $day['max']))
            ->values()->all();
    }

    /** @return list<DomainPhase> in the order the phases are kept: by start */
    public function phases(): array
    {
        return $this->week->train->phases->map(fn (Phase $phase) => PlanSnapshot::domainPhase($phase))->values()->all();
    }

    /**
     * ANA-008: the saved mesocycles, with their dates. A draft has none and is left out. One ended early runs to the
     * day before it was ended (MESO-043).
     *
     * @return list<ProgressMesocycle>
     */
    public function mesocycles(): array
    {
        return $this->week->train->mesocycles
            ->filter(fn (Mesocycle $mesocycle) => $mesocycle->isSaved())
            ->map(fn (Mesocycle $mesocycle) => new ProgressMesocycle(
                id: (string) $mesocycle->id,
                phaseId: $mesocycle->phase_id === null ? null : (string) $mesocycle->phase_id,
                name: $mesocycle->name ?? 'Mesocycle',
                start: TrainSnapshot::day($mesocycle->start_date),
                weeks: PlanSnapshot::effectiveWeeks($mesocycle),
            ))->values()->all();
    }

    /** SET-012: the user's wall-clock time at a weigh-in, labelled UTC, so its Y-m-d is the user's local day. */
    public function weighedAt(WeighIn $weighIn): DateTimeImmutable
    {
        return UserDay::clockIn($this->week->train->zone, $weighIn->weighed_at);
    }

    /** WT-010: the trend over all the user's weigh-ins, each on the user's local day. */
    public function trend(): TrendWeight
    {
        return $this->trend ??= new TrendWeight(
            $this->weighIns->map(fn (WeighIn $weighIn) => new DomainWeighIn(
                (string) $weighIn->id, $this->weighedAt($weighIn), (float) $weighIn->weight_kg,
            ))->values()->all(),
            $this->zone(),
        );
    }

    /** The plan's start; without a plan, the first phase's, then the first weigh-in's day, then today. */
    public function planStart(): DateTimeImmutable
    {
        if ($this->plan() !== null) {
            return TrainSnapshot::day($this->plan()->start_date);
        }
        $phases = $this->phases();

        return $phases === [] ? ($this->trend()->points[0]->day ?? $this->today()) : min(array_map(fn (DomainPhase $phase) => $phase->start, $phases));
    }

    /**
     * ANA-007, WO-025: the working sets of sessions that belong to a mesocycle, one per set the loader counted, with the
     * muscle of their exercise; a set of an exercise the user cannot see is left out.
     *
     * @return list<LoggedWorkingSet>
     */
    public function workingSets(): array
    {
        $muscles = $this->exercises->mapWithKeys(fn (Exercise $exercise) => [(int) $exercise->id => $exercise->muscle_group]);
        $working = [];
        foreach ($this->sets as $row) {
            $muscle = $muscles->get((int) $row->exercise_id);
            if ($muscle === null) {
                continue;
            }
            for ($count = (int) $row->sets; $count > 0; $count--) {
                $working[] = new LoggedWorkingSet((string) $row->mesocycle_id, (int) $row->week, $muscle);
            }
        }

        return $working;
    }

    /**
     * ANA-009, EX-013: the exercises the user can see that have a last-used date, most recent first.
     *
     * @return list<Exercise>
     */
    public function usedExercises(): array
    {
        $used = $this->exercises->filter(fn (Exercise $exercise) => isset($this->lastUsed[(int) $exercise->id]))->values()->all();
        usort($used, fn (Exercise $a, Exercise $b) => [$this->lastUsed[(int) $b->id], $a->id] <=> [$this->lastUsed[(int) $a->id], $b->id]);

        return $used;
    }
}
