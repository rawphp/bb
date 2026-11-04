<?php

namespace App\Support\Review;

use App\Domain\Model\MealTarget;
use App\Domain\Model\Mesocycle as DomainMesocycle;
use App\Domain\Model\Phase as DomainPhase;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Plan as DomainPlan;
use App\Domain\Model\PlanWeek as DomainPlanWeek;
use App\Domain\Plan\PlanRules;
use App\Domain\Plan\PlanWeekGrid;
use App\Domain\Review\ReviewWeek;
use App\Domain\Review\ReviewWorld;
use App\Domain\WeekBuilder\WeekSessions;
use App\Models\DayTarget;
use App\Models\Mesocycle;
use App\Models\PlanWeek;
use App\Models\Profile;
use App\Support\Mesocycles\TrainSnapshot;
use Closure;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The stored plan read as the domain sees it (rp-clone ReviewPlanContext): its grid, the flags each plan week really
 * has (the row's, and the deload its mesocycle puts there), the finished weeks' targets, and the world next week is
 * built in.
 */
final class ReviewPlanContext
{
    /** @var array<int, array{mesocycle: DomainMesocycle, startWeek: int, pausedWeeks: list<int>, record: ?Mesocycle}> by week and fallback */
    private array $covering = [];

    private function __construct(
        public readonly ReviewData $data,
        public readonly DomainPlan $plan,
        public readonly PlanWeekGrid $grid,
        private readonly TrainSnapshot $train,
    ) {}

    /** Null without a plan. */
    public static function from(ReviewData $data): ?self
    {
        if ($data->plan === null) {
            return null;
        }
        $plan = ReviewPlanRebuild::plan($data->plan, $data->phases);
        $train = new TrainSnapshot(
            today: $data->today,
            plan: $data->plan,
            profile: $data->profile,
            mesocycles: $data->mesocycles,
            phases: $data->phases,
            sessions: $data->sessions,
            sets: $data->sets,
            feedback: $data->muscleFeedback,
            planWeeks: $data->planWeeks,
            dayTargets: new Collection,
            exercises: $data->exercises,
        );

        return new self($data, $plan, PlanWeekGrid::forPlan($plan), $train);
    }

    /** PLAN-002: the phase covering a plan week, if any. */
    public function phase(int $coveringWeek): ?DomainPhase
    {
        $index = PlanRules::phaseIndexCoveringWeek($coveringWeek, $this->plan, $this->grid);

        return $index === null ? null : $this->plan->phases[$index];
    }

    /** The stored row of the plan week that starts on a day, if any. */
    public function row(DateTimeImmutable $start): ?PlanWeek
    {
        return $this->train->planWeekRow(TrainSnapshot::day($start));
    }

    /**
     * PLAN-002, SCH-060: the mesocycle covering a plan week, with where it starts and the weeks away pauses.
     *
     * @return array{mesocycle: DomainMesocycle, startWeek: int, pausedWeeks: list<int>, record: ?Mesocycle}
     */
    public function coveringMesocycle(int $week, PhaseType $fallback): array
    {
        return $this->covering[$week.$fallback->value] ??= ReviewPlanRebuild::coveringMesocycle($week, $this->train, $this->grid, $fallback);
    }

    /** A plan week with every flag that applies (PLAN-022, SCH-060): its row's, and a deload its mesocycle puts there. */
    public function flagged(int $index, PhaseType $fallback): DomainPlanWeek
    {
        $week = $this->grid->planWeek($index);
        $row = $this->row($week->start);
        $covering = $this->coveringMesocycle($index, $fallback);
        $result = WeekSessions::build(
            planWeek: $week, week: $index, mesocycle: $covering['mesocycle'], mesocycleStartWeek: $covering['startWeek'],
            trainingDays: [], awayTraining: ReviewPlanRebuild::away($row)?->training, flaggedDeload: (bool) ($row->is_deload ?? false),
            pausedWeeks: $covering['pausedWeeks'],
        );

        return new DomainPlanWeek(
            $week->start, $week->dayCount, $result->isDeload, (bool) ($row->is_diet_break ?? false), (bool) ($row->is_away ?? false),
        );
    }

    /**
     * The finished weeks with a stored target, oldest first, up to and including `$through`; at most `$count` of them.
     * Intake is the calories checked in on each day with a check-in (REV-021). SCH-064: each week carries its row's
     * rough mark.
     *
     * @param  list<array{id: string, date: string, checkInKcal: ?float, eaten: ?MealTarget}>  $meals
     * @param  ?Closure(DomainPlanWeek): bool  $trainingFatigueHigh  REV-040: whether a week's training fatigue was high
     * @return list<ReviewWeek>
     */
    public function reviewWeeks(int $through, int $count, PhaseType $fallback, array $meals, ?Closure $trainingFatigueHigh = null): array
    {
        $weeks = [];
        if ($through < 0) {
            return $weeks;
        }
        for ($index = max(0, $through - $count + 1); $index <= $through; $index++) {
            $week = $this->flagged($index, $fallback);
            $days = array_map(fn (DateTimeImmutable $day) => $day->format('Y-m-d'), $week->days());
            $targets = $this->data->dayTargets->filter(fn (DayTarget $t) => in_array(TrainSnapshot::day($t->date)->format('Y-m-d'), $days, true));
            if ($targets->isEmpty()) {
                continue;
            }
            $intake = [];
            foreach ($days as $day) {
                $eaten = array_filter($meals, fn (array $meal) => $meal['date'] === $day && $meal['checkInKcal'] !== null);
                if ($eaten !== []) {
                    $intake[] = (float) array_sum(array_column($eaten, 'checkInKcal'));
                }
            }
            $weeks[] = new ReviewWeek(
                week: $week,
                loggedIntakeKcal: $intake,
                targetKcal: $targets->sum(fn (DayTarget $t) => $t->kcal) / $targets->count(),
                isRough: (bool) ($this->row($week->start)->is_rough ?? false),
                trainingFatigueHigh: $trainingFatigueHigh !== null && $trainingFatigueHigh($week),
            );
        }

        return $weeks;
    }

    /**
     * The world the week `$index` is built in: the profile at the trend weight (WT-011), its schedule, the mesocycle
     * covering it and what is already marked on its row (SCH-060, MESO-044).
     */
    public function world(Profile $profile, ?float $trendKg, int $index, PhaseType $fallback): ReviewWorld
    {
        $row = $this->row($this->grid->start($index));
        $covering = $this->coveringMesocycle($index, $fallback);

        return new ReviewWorld(
            plan: $this->plan,
            profile: ReviewPlanRebuild::domainProfile($profile, $trendKg),
            schedule: ReviewPlanRebuild::schedule($profile),
            mesocycle: $covering['mesocycle'],
            mesocycleStartWeek: $covering['startWeek'],
            nextWeek: $index,
            newId: fn (): string => strtolower((string) Str::uuid7()),
            away: ReviewPlanRebuild::away($row),
            storedDietBreak: (bool) ($row->is_diet_break ?? false),
            storedDeload: (bool) ($row->is_deload ?? false),
            dietBreakTurnedOff: (bool) ($row->diet_break_off ?? false),
            pausedWeeks: $covering['pausedWeeks'],
            otherActivities: ReviewPlanRebuild::activitiesByDay($this->data->otherActivities),
        );
    }
}
