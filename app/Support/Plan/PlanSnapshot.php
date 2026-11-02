<?php

namespace App\Support\Plan;

use App\Domain\Mesocycle\MesocycleCalendar;
use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\Phase as DomainPhase;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Plan as DomainPlan;
use App\Domain\Model\WeightUnit;
use App\Domain\Plan\DatedMesocycle;
use App\Domain\Plan\DeloadCalories;
use App\Domain\Plan\PlanRules;
use App\Domain\Plan\PlanWeekGrid;
use App\Domain\Plan\WeekRange;
use App\Domain\Progress\PlanWeekDays;
use App\Models\Mesocycle;
use App\Models\Phase;
use App\Models\Plan;
use App\Models\PlanWeek;
use App\Models\Profile;
use App\Models\User;
use App\Models\WeighIn;
use App\Support\Mesocycles\TrainSnapshot;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Collection;

/**
 * The plan as every Plan read and write sees it (PLAN-001, PLAN-008): the phases sorted by start, the saved
 * mesocycles placed on the plan-week grid with any paused weeks inside them (PLAN-002, SCH-060), the flagged plan
 * weeks and the weigh-ins. A write refuses exactly what a read does not offer, because both ask here.
 *
 * Dates are whole days at midnight UTC, read from their Y-m-d. A phase with no end (PH-005) fills the weeks up to
 * {@see self::OPEN}.
 */
final class PlanSnapshot
{
    /** The upper week of a phase with no end. */
    public const OPEN = PHP_INT_MAX;

    public readonly PlanWeekGrid $grid;

    public readonly DomainPlan $domain;

    /** The plan week today falls in; null before the plan starts. */
    public readonly ?int $todayWeek;

    /** @var list<PlacedMesocycle> sorted by start week */
    public readonly array $placed;

    /** @var list<int> SCH-060: away weeks with training paused */
    public readonly array $pausedWeeks;

    /** @var array<int, TimelineWeek> each plan week's marks, worked out once */
    private array $timelineWeeks = [];

    /** @var array<string, PlanWeek> the flagged plan weeks by their Y-m-d start */
    private array $rowsByStart = [];

    /**
     * @param  Collection<int, Phase>  $phases  sorted by start
     * @param  Collection<int, Mesocycle>  $mesocycles  saved ones
     * @param  Collection<int, PlanWeek>  $planWeeks
     * @param  Collection<int, WeighIn>  $weighIns
     */
    public function __construct(
        public readonly DateTimeImmutable $today,
        public readonly Plan $plan,
        public readonly ?Profile $profile,
        public readonly Collection $phases,
        public readonly Collection $mesocycles,
        public readonly Collection $planWeeks,
        public readonly Collection $weighIns,
    ) {
        $this->domain = new DomainPlan(
            id: (string) $plan->id,
            start: self::day($plan->start_date),
            reviewWeekday: PlanWeekDays::calendarWeekdayFromIso($plan->review_weekday),
            phases: $phases->map(fn (Phase $phase) => self::domainPhase($phase))->values()->all(),
        );
        $this->grid = PlanWeekGrid::forPlan($this->domain);
        $this->todayWeek = $this->grid->weekIndexContaining($today);

        foreach ($planWeeks as $row) {
            $this->rowsByStart[self::day($row->start_date)->format('Y-m-d')] = $row;
        }

        $paused = [];
        foreach ($planWeeks as $row) {
            if ($row->is_away && $row->away_training === 'pause') {
                $week = $this->grid->weekIndexContaining(self::day($row->start_date));
                if ($week !== null) {
                    $paused[] = $week;
                }
            }
        }
        $this->pausedWeeks = array_values(array_unique($paused));

        $saved = $mesocycles->values()->all();
        $places = PlanRules::placements(
            array_map(fn (Mesocycle $m) => new DatedMesocycle(self::day($m->start_date), self::effectiveWeeks($m)), $saved),
            $this->grid,
            $this->pausedWeeks,
        );
        $placed = [];
        foreach ($saved as $index => $mesocycle) {
            if ($places[$index] !== null) {
                $placed[] = new PlacedMesocycle($mesocycle, $places[$index], $mesocycle->ended_on !== null);
            }
        }
        usort($placed, fn (PlacedMesocycle $a, PlacedMesocycle $b) => $a->placed->startWeek <=> $b->placed->startWeek);
        $this->placed = $placed;
    }

    /** Everything for one user; null when the user has no plan yet. */
    public static function load(User $user): ?self
    {
        $plan = Plan::query()->where('user_id', $user->id)->first();
        if ($plan === null) {
            return null;
        }

        return new self(
            today: self::day(CarbonImmutable::now()),
            plan: $plan,
            profile: Profile::query()->where('user_id', $user->id)->first(),
            phases: Phase::query()->where('user_id', $user->id)->orderBy('start_date')->get(),
            mesocycles: Mesocycle::savedBy($user)->get(),
            planWeeks: PlanWeek::query()->where('user_id', $user->id)->get(),
            weighIns: WeighIn::query()->where('user_id', $user->id)->orderBy('weighed_at')->get(),
        );
    }

    public static function day(\DateTimeInterface|string $date): DateTimeImmutable
    {
        return TrainSnapshot::day($date);
    }

    public static function domainPhase(Phase $phase): DomainPhase
    {
        return new DomainPhase(
            id: (string) $phase->id,
            type: $phase->type,
            start: self::day($phase->start_date),
            end: $phase->end_date === null ? null : self::day($phase->end_date),
            targetRatePercentPerWeek: (float) $phase->target_rate_percent_per_week,
            eventDate: $phase->event_date === null ? null : self::day($phase->event_date),
        );
    }

    /** MESO-043: an ended mesocycle runs to the day before it was ended. */
    public static function effectiveWeeks(Mesocycle $mesocycle): int
    {
        $weeks = (int) $mesocycle->weeks;
        if ($mesocycle->ended_on === null) {
            return $weeks;
        }
        $days = max(0, (int) self::day($mesocycle->start_date)->diff(self::day($mesocycle->ended_on))->format('%r%a'));

        return max(1, min($weeks, intdiv($days + 6, 7)));
    }

    // MARK: the profile

    /** PLAN-007: no rates, no calories. */
    public function trainingOnly(): bool
    {
        return (bool) ($this->profile->training_only ?? false);
    }

    public function experience(): ExperienceLevel
    {
        return ExperienceLevel::tryFrom((string) ($this->profile->experience ?? '')) ?? ExperienceLevel::Intermediate;
    }

    public function weightUnit(): WeightUnit
    {
        return WeightUnit::tryFrom((string) ($this->profile->weight_unit ?? '')) ?? WeightUnit::Kg;
    }

    // MARK: phases

    /** The weeks a phase fills; a phase off the grid is read to the weeks it touches. */
    public function weeksOf(Phase $phase): WeekRange
    {
        $domain = self::domainPhase($phase);
        $range = PlanRules::weeks($domain, $this->grid);
        if ($range !== null) {
            return $range;
        }
        $start = $this->grid->weekIndexContaining($domain->start) ?? 0;
        if ($domain->end === null) {
            return new WeekRange($start, self::OPEN);
        }
        $end = ($this->grid->weekIndexContaining($domain->end) ?? $start) + ($this->grid->isBoundary($domain->end) ? 0 : 1);

        return new WeekRange($start, max($start, $end));
    }

    public function phaseIndexCoveringWeek(int $week): ?int
    {
        foreach ($this->phases->values() as $index => $phase) {
            $range = $this->weeksOf($phase);
            if ($week >= $range->lower && $week < $range->upper) {
                return $index;
            }
        }

        return null;
    }

    /** The phase running today: none before the plan starts (-1); after the last phase, the last one. */
    public function currentPhaseIndex(): int
    {
        if ($this->todayWeek === null) {
            return -1;
        }

        return $this->phaseIndexCoveringWeek($this->todayWeek) ?? ($this->phases->isEmpty() ? -1 : $this->phases->count() - 1);
    }

    /** The phase whose weeks hold today, if any (PH-006, PH-007). */
    public function runningPhase(): ?Phase
    {
        $index = $this->todayWeek === null ? null : $this->phaseIndexCoveringWeek($this->todayWeek);

        return $index === null ? null : $this->phases->values()[$index];
    }

    public function findPhase(string $id): ?Phase
    {
        return $this->phases->first(fn (Phase $phase) => strcasecmp((string) $phase->id, $id) === 0);
    }

    /** @return list<Phase> the phases after this one */
    public function laterPhases(Phase $phase): array
    {
        $values = $this->phases->values()->all();
        $index = array_search($phase, $values, true);

        return $index === false ? [] : array_slice($values, $index + 1);
    }

    // MARK: weeks

    /** The first week a change can start from: the week after today's (PH-009). */
    public function nextWeek(): int
    {
        return ($this->todayWeek ?? -1) + 1;
    }

    public function dayOfWeek(int $week): DateTimeImmutable
    {
        return $this->grid->start($week);
    }

    public function weekRow(int $week): ?PlanWeek
    {
        return $this->rowsByStart[$this->grid->start($week)->format('Y-m-d')] ?? null;
    }

    /** Where a saved mesocycle stands in time, as the mesocycle writes see it (MESO-042). */
    public function calendar(Mesocycle $mesocycle): MesocycleCalendar
    {
        $start = self::day($mesocycle->start_date);

        return new MesocycleCalendar(
            start: $start,
            weeks: (int) $mesocycle->weeks,
            endedOn: $mesocycle->ended_on === null ? null : self::day($mesocycle->ended_on),
            grid: $this->grid,
            today: $this->today,
            nextStart: $this->mesocycles
                ->map(fn (Mesocycle $m) => self::day($m->start_date))
                ->filter(fn (DateTimeImmutable $other) => $other > $start)
                ->min(),
        );
    }

    /** The mesocycle covering a week (PLAN-002): of overlapping ones, the one that started last. */
    public function mesocycleCoveringWeek(int $week): ?PlacedMesocycle
    {
        $covering = null;
        foreach ($this->placed as $placed) {
            if ($placed->placed->contains($week) && $placed->placed->startWeek > ($covering->placed->startWeek ?? PHP_INT_MIN)) {
                $covering = $placed;
            }
        }

        return $covering;
    }

    /** @return list<PlacedMesocycle> */
    public function unended(): array
    {
        return array_values(array_filter($this->placed, fn (PlacedMesocycle $p) => ! $p->isEnded));
    }

    /**
     * A plan week as the timeline marks it (PLAN-003, PLAN-022 to PLAN-024): a deload when a mesocycle's deload
     * falls there or the week is flagged one, with its label in the type of the phase governing it (PLAN-002).
     */
    public function timelineWeek(int $week): TimelineWeek
    {
        return $this->timelineWeeks[$week] ??= $this->markWeek($week);
    }

    private function markWeek(int $week): TimelineWeek
    {
        $row = $this->weekRow($week);
        $ending = null;
        foreach ($this->placed as $placed) {
            if (! $placed->isEnded && $placed->placed->deloadWeek() === $week) {
                $ending = $placed;
                break;
            }
        }
        $isDeload = $ending !== null || ($row->is_deload ?? false);

        $phaseIndex = $ending === null ? null : PlanRules::governingPhaseIndex($week, $ending->placed, $this->domain, $this->grid);
        $phaseIndex ??= $this->phaseIndexCoveringWeek($week);
        $type = $phaseIndex === null ? null : $this->phases->values()[$phaseIndex]->type;

        $trainingOnly = $this->trainingOnly();
        $isOff = ! $trainingOnly && $isDeload && ($type?->isFatLoss() ?? false) && ($row->diet_break_off ?? false);
        $label = null;
        if ($isDeload) {
            $label = match (true) {
                $trainingOnly, $type === null => 'Deload',
                default => DeloadCalories::label($type, $isOff) ?? 'Deload · deficit calories',
            };
        }

        return new TimelineWeek(
            index: $week,
            start: $this->grid->start($week),
            dayCount: $this->grid->lengthInDays($week),
            isPast: $this->todayWeek !== null && $week < $this->todayWeek,
            isDeload: $isDeload,
            deloadLabel: $label,
            canTurnOffDietBreak: ! $trainingOnly && $isDeload && ! $isOff && $type !== null && DeloadCalories::canTurnOffDietBreak($type),
            dietBreakIsOff: $isOff,
        );
    }

    /**
     * PLAN-025: the weeks of a range that run at maintenance calories in a cut: diet-break deloads and unplanned
     * breaks. Only a Fat loss or Mini-cut has any, and training-only has none.
     */
    public function dietBreakWeeks(int $from, int $to, PhaseType $type): int
    {
        if (! $type->isFatLoss() || $this->trainingOnly()) {
            return 0;
        }
        $count = 0;
        for ($week = max(0, $from); $week < $to; $week++) {
            $marks = $this->timelineWeek($week);
            if (($marks->isDeload && ! $marks->dietBreakIsOff) || ($this->weekRow($week)->is_diet_break ?? false)) {
                $count++;
            }
        }

        return $count;
    }
}
