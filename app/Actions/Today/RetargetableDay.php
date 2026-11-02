<?php

namespace App\Actions\Today;

use App\Domain\Model\PlanWeek;
use App\Domain\Retarget\RetargetContext;
use App\Domain\Retarget\RetargetDay;
use App\Domain\Retarget\RetargetRefused;
use App\Models\PlanWeek as PlanWeekRow;
use App\Models\User;
use App\Support\Today\DayPlans;
use App\Support\Today\RetargetDays;
use App\Support\Today\SessionWeek;
use App\Support\Today\TodaySnapshot;
use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Str;

/**
 * A stored day of today or later as the Today writes re-target it (SCH-031, SCH-032, SCH-036, SCH-038): its meals
 * and target from DayPlans, the session Today shows on it and its Other activity, with the settings the rule places
 * and splits with (RetargetDays). A skipped session's energy has left the day (SCH-053), so it is not on it.
 */
final readonly class RetargetableDay
{
    /** NUT-024. */
    public const PAST = 'A past day can\'t be changed.';

    public const NO_MEALS = 'This day has no meals planned yet.';

    private function __construct(
        /** Y-m-d */
        public string $date,
        public TodaySnapshot $snapshot,
        public DayPlans $plans,
        public RetargetDay $day,
        public RetargetContext $context,
    ) {}

    /**
     * @param  ?TodaySnapshot  $snapshot  Today around `$date`, when the caller has read it
     *
     * @throws DomainException for a past day, a day with no target, or without the eating window or a weight
     */
    public static function load(User $user, string $date, ?TodaySnapshot $snapshot = null): self
    {
        $day = DayPlans::day($date);
        $snapshot ??= TodaySnapshot::load($user, $date);
        if ($day < $snapshot->today()) {
            throw new DomainException(self::PAST);
        }
        $plans = DayPlans::load($user, [$date]);
        $plan = $plans->plan($date) ?? throw new DomainException(self::NO_MEALS);
        $isDeload = $snapshot->isDeload($day);
        $context = RetargetDays::context($user, $day, $isDeload, self::weekRow($snapshot, $day));
        $workout = $snapshot->workout($day);
        $scheduled = $snapshot->planning($day)['plan']->session ?? null;
        $session = $workout === null || $scheduled === null || $scheduled->isSkipped ? null : SessionWeek::weekWorkout($scheduled, $isDeload);

        return new self($date, $snapshot, $plans, RetargetDays::day($plan, $session, RetargetDays::activity($user, $date), $context), $context);
    }

    /** Saves the rule's day onto this day's stored row. */
    public function save(RetargetDay $day): void
    {
        RetargetDays::save($this->plans->row($this->date), $day);
    }

    /** The plan week the day falls in; null without a plan. */
    public function planWeek(): ?PlanWeek
    {
        $grid = $this->snapshot->train->grid();
        $index = $grid?->weekIndexContaining(DayPlans::day($this->date));

        return $index === null ? null : $grid->planWeek($index);
    }

    /** @return list<string> the days of the day's plan week (Y-m-d), for the week strip; the day alone without a plan */
    public function weekDates(): array
    {
        $week = $this->planWeek();

        return $week === null ? [$this->date] : array_map(fn (DateTimeImmutable $day) => $day->format('Y-m-d'), $week->days());
    }

    /** An id for a meal the rule creates (the intra-workout shake). */
    public static function newId(): string
    {
        return (string) Str::uuid7();
    }

    /** The rule's refusal, as the user reads it. */
    public static function refused(RetargetRefused $refused): DomainException
    {
        return new DomainException(ResolveMissedSession::refusal($refused->refusal));
    }

    private static function weekRow(TodaySnapshot $snapshot, DateTimeImmutable $day): ?PlanWeekRow
    {
        $grid = $snapshot->train->grid();
        $index = $grid?->weekIndexContaining($day);

        return $index === null ? null : $snapshot->train->planWeekRow($grid->start($index));
    }
}
