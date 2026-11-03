<?php

namespace App\Actions\Plan;

use App\Domain\Plan\PlanRebuild;
use App\Domain\Plan\PlanRebuildRefusal;
use App\Domain\Plan\PlanRebuildRefused;
use App\Domain\Plan\PlanRules;
use App\Domain\WeekBuilder\WeekDay;
use App\Domain\WeekBuilder\WeekMeal;
use App\Domain\WeekBuilder\WeekSessions\SessionRow;
use App\Domain\WeekBuilder\WorkoutSessionRecord;
use App\Models\DayTarget;
use App\Models\Mesocycle;
use App\Models\OtherActivity;
use App\Models\Phase;
use App\Models\Plan;
use App\Models\PlanWeek;
use App\Models\Profile;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Support\Mesocycles\TrainSnapshot;
use App\Support\Plan\PlanRebuildRows;
use App\Support\Plan\PlanSnapshot;
use App\Support\Today\DayPlans;
use App\Support\Today\TodayWeight;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RebuildTargets
{
    /**
     * SET-004: rebuilds the days after today in the current plan week from the stored profile and plan, and saves
     * each one as its `day_targets` row (created, or updated in place). Today and earlier days keep their targets.
     *
     * @param  float  $calorieChangePerDay  kcal a day on top of the recommendation (ONB-017); 0 is the formula (NUT-002)
     * @param  bool  $beforeStartBuildsFirstWeek  before the plan starts, rebuild its whole first week instead of refusing
     * @return list<string> the dates written, Y-m-d, in order; empty on the week's last day and for a training-only profile
     *
     * @throws PlanRebuildRefused the rules refused; nothing is written
     */
    public function __invoke(User $user, float $calorieChangePerDay = 0, bool $beforeStartBuildsFirstWeek = false): array
    {
        return DB::transaction(fn (): array => $this->rebuild($user, $calorieChangePerDay, $beforeStartBuildsFirstWeek));
    }

    /** @return list<string> */
    private function rebuild(User $user, float $calorieChangePerDay, bool $beforeStartBuildsFirstWeek): array
    {
        // The plan row is locked first, before any other read: a plain read would fix this transaction's view (MySQL's
        // REPEATABLE READ), so one that waited on this lock would miss the rows the rebuild before it wrote.
        $plan = Plan::query()->where('user_id', $user->id)->lockForUpdate()->first();
        $profile = Profile::query()->where('user_id', $user->id)->sharedLock()->first();
        // SAFE-003: a training-only profile has no targets to rebuild, before anything else is looked at.
        if ($profile?->training_only) {
            return [];
        }
        if ($plan === null || $profile === null) {
            throw new PlanRebuildRefused(PlanRebuildRefusal::planNotStarted());
        }

        $today = TrainSnapshot::day(CarbonImmutable::now());
        $phases = Phase::query()->where('user_id', $user->id)->orderBy('start_date')->sharedLock()->get();
        $mesocycles = Mesocycle::query()->where('user_id', $user->id)->sharedLock()->get();
        $planWeeks = PlanWeek::query()->where('user_id', $user->id)->sharedLock()->get();
        // A draft, or a mesocycle with no days, never covers a week (PLAN-002).
        $snapshot = new PlanSnapshot(
            $today, $plan, $profile, $phases,
            $mesocycles->filter(fn (Mesocycle $m) => $m->isSaved() && $m->dayRows() !== [])->values(),
            $planWeeks, new Collection,
        );
        $train = new TrainSnapshot(
            $today, $plan, $profile, $mesocycles, $phases, new Collection, new Collection, new Collection, $planWeeks,
            new Collection, new Collection,
        );

        $week = PlanRebuild::week($snapshot->domain, $today, $beforeStartBuildsFirstWeek);
        $phaseIndex = PlanRules::phaseIndexCoveringWeek($week, $snapshot->domain, $snapshot->grid)
            ?? throw new PlanRebuildRefused(PlanRebuildRefusal::noPhase());
        $phaseType = $snapshot->domain->phases[$phaseIndex]->type;
        $covering = PlanRebuildRows::mesocycle($snapshot, $train, $week, $phaseType);
        $row = $snapshot->weekRow($week);
        $dates = array_map(fn (DateTimeImmutable $day) => $day->format('Y-m-d'), $snapshot->grid->planWeek($week)->days());

        $stored = DayTarget::query()->where('user_id', $user->id)->whereIn('date', $dates)->orderBy('created_at')->orderBy('id')->lockForUpdate()->get()
            ->unique(fn (DayTarget $target) => TrainSnapshot::day($target->date)->format('Y-m-d'))
            ->keyBy(fn (DayTarget $target) => TrainSnapshot::day($target->date)->format('Y-m-d'));

        $days = PlanRebuild::days(
            plan: $snapshot->domain,
            profile: PlanRebuildRows::profile($profile, TodayWeight::kg($user, $profile) ?? (float) $profile->weight_kg),
            mesocycle: $covering['mesocycle'],
            mesocycleStartWeek: $covering['startWeek'],
            week: $week,
            schedule: PlanRebuildRows::schedule($profile),
            today: $today,
            newId: fn (): string => strtolower((string) Str::uuid7()),
            away: PlanRebuildRows::away($row),
            isDietBreak: (bool) ($row->is_diet_break ?? false),
            flaggedDeload: (bool) ($row->is_deload ?? false),
            dietBreakTurnedOff: (bool) ($row->diet_break_off ?? false),
            calorieChangePerDay: $calorieChangePerDay,
            pausedWeeks: $snapshot->pausedWeeks,
            sessionRows: $this->sessionRows($user, $covering['mesocycle']->id),
            otherActivities: PlanRebuildRows::activitiesByDay(
                OtherActivity::query()->where('user_id', $user->id)->whereIn('date', $dates)->orderBy('created_at')->orderBy('id')->sharedLock()->get(),
            ),
            storedMeals: $stored->map(fn (DayTarget $target) => array_map(
                fn (array $meal) => ['id' => strtolower((string) $meal['id']), 'name' => (string) ($meal['name'] ?? '')], $target->meals,
            ))->all(),
        );

        return array_map(fn (WeekDay $day) => $this->save($user, $day, $stored), $days);
    }

    /**
     * SCH-051, SCH-053: the stored rows of the covering mesocycle's sessions, which move or skip its planned ones.
     *
     * @return list<SessionRow>
     */
    private function sessionRows(User $user, string $mesocycleId): array
    {
        if ($mesocycleId === '') {
            return [];
        }
        $records = WorkoutSession::query()->where('user_id', $user->id)->where('mesocycle_id', $mesocycleId)->sharedLock()->get()
            ->map(fn (WorkoutSession $session) => new WorkoutSessionRecord(
                id: (string) $session->id,
                mesocycleId: $session->mesocycle_id,
                week: $session->week,
                day: $session->day,
                isSkipped: $session->is_skipped,
                plannedStartAt: $session->planned_start_at === null ? null : DayPlans::instant($session->planned_start_at),
                actualStartAt: $session->actual_start_at === null ? null : DayPlans::instant($session->actual_start_at),
            ))->values()->all();

        return SessionRow::rows($records, $mesocycleId);
    }

    /**
     * Saves a built day on its stored row, or on a new one. Only the model writes, so its hooks keep the meal index in
     * step and a reversible caller's undo sees every change (CAP-013).
     *
     * @param  Collection<string, DayTarget>  $stored  by Y-m-d
     */
    private function save(User $user, WeekDay $day, Collection $stored): string
    {
        $date = $day->day->date->format('Y-m-d');
        $target = $day->target;
        $row = $stored->get($date) ?? new DayTarget(['date' => $date]);
        $row->user_id = $user->id;
        $row->fill([
            'day_type' => $day->day->type,
            'kcal' => $target->kcal,
            'protein_g' => $target->proteinG,
            'fat_g' => $target->fatG,
            'carbs_g' => $target->carbsG,
            'meals' => array_map(fn (WeekMeal $meal) => [
                'id' => $meal->id(),
                'name' => $meal->meal->name,
                'time' => (string) $meal->time,
                'kcal' => $meal->meal->target->kcal,
                'protein_g' => $meal->meal->target->proteinG,
                'fat_g' => $meal->meal->target->fatG,
                'carbs_g' => $meal->meal->target->carbsG,
            ], $day->meals),
        ])->save();

        return $date;
    }
}
