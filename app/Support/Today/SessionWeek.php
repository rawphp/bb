<?php

namespace App\Support\Today;

use App\Domain\Energy\EnergyRules;
use App\Domain\Model\PlanWeek;
use App\Domain\Placement\ClockTime;
use App\Domain\Placement\ScheduledSession;
use App\Domain\Placement\TrainingDay;
use App\Domain\Placement\WorkoutTime;
use App\Domain\Progress\PlanWeekDays;
use App\Domain\WeekBuilder\AwayWeek\Training;
use App\Domain\WeekBuilder\WeekSessions;
use App\Domain\WeekBuilder\WeekSessions\LoggedDay;
use App\Domain\WeekBuilder\WeekSessions\Result;
use App\Domain\WeekBuilder\WeekSessions\SessionRow;
use App\Domain\WeekBuilder\WeekWorkout;
use App\Domain\WeekBuilder\WorkoutSessionRecord;
use App\Models\Mesocycle;
use App\Models\PlanWeek as PlanWeekRow;
use App\Models\WorkoutSession;
use App\Support\Mesocycles\TrainSnapshot;
use DateTimeImmutable;
use DomainException;

/**
 * The sessions of the plan week holding today, placed on its days as Today places them (SCH-040, SCH-050 to
 * SCH-057): the mesocycle's week, the deload, an away pause, the training weekdays and each session's stored row.
 */
final readonly class SessionWeek
{
    private function __construct(
        public TrainSnapshot $snapshot,
        public Mesocycle $mesocycle,
        public PlanWeek $planWeek,
        public Result $result,
        public ?PlanWeekRow $row,
    ) {}

    /** @throws DomainException without a plan, or when today is outside it */
    public static function load(TrainSnapshot $snapshot, Mesocycle $mesocycle): self
    {
        $grid = $snapshot->grid() ?? throw new DomainException('There is no plan to place this session in.');
        $index = $grid->weekIndexContaining($snapshot->today) ?? throw new DomainException('Today is outside your plan.');
        $planWeek = $grid->planWeek($index);
        $row = $snapshot->planWeekRow($planWeek->start);
        $sessions = $snapshot->sessions->filter(fn (WorkoutSession $session) => $session->mesocycle_id === $mesocycle->id);
        $pausedWeeks = $snapshot->planWeeks
            ->filter(fn (PlanWeekRow $week) => $week->is_away && $week->away_training === Training::Pause->value)
            ->map(fn (PlanWeekRow $week) => $grid->weekIndexContaining(TrainSnapshot::day($week->start_date)))
            ->filter(fn (?int $week) => $week !== null)
            ->values()->all();

        $result = WeekSessions::build(
            planWeek: $planWeek,
            week: $index,
            mesocycle: $snapshot->domain($mesocycle),
            mesocycleStartWeek: $grid->weekIndexContaining(TrainSnapshot::day($mesocycle->start_date)) ?? 0,
            trainingDays: self::trainingDays($snapshot),
            awayTraining: $row !== null && $row->is_away && $row->away_training !== null ? Training::from($row->away_training) : null,
            flaggedDeload: (bool) ($row->is_deload ?? false),
            logged: $sessions->filter(fn (WorkoutSession $session) => self::isLogged($session))
                ->map(fn (WorkoutSession $session) => new LoggedDay($session->week, (int) $session->day))->values()->all(),
            pausedWeeks: $pausedWeeks,
            rows: SessionRow::rows($sessions->map(fn (WorkoutSession $session) => new WorkoutSessionRecord(
                id: (string) $session->id,
                mesocycleId: $session->mesocycle_id,
                week: $session->week,
                day: $session->day,
                isSkipped: $session->is_skipped,
                plannedStartAt: $session->planned_start_at === null ? null : DayPlans::instant($session->planned_start_at),
                actualStartAt: $session->actual_start_at === null ? null : DayPlans::instant($session->actual_start_at),
            ))->values()->all(), $mesocycle->id),
        );

        return new self($snapshot, $mesocycle, $planWeek, $result, $row);
    }

    /** SCH-057: a session counts as done once it is started or skipped; a row that only moved it does not. */
    public static function isLogged(WorkoutSession $session): bool
    {
        return $session->actual_start_at !== null || $session->is_skipped;
    }

    /** The session numbered `$day` (from 1) in mesocycle week `$week` (from 1), when this plan week holds it. */
    public function session(int $week, int $day): ?ScheduledSession
    {
        if ($this->result->mesocycleWeek === null || $this->result->mesocycleWeek + 1 !== $week) {
            return null;
        }

        return $this->result->scheduled[$day - 1] ?? null;
    }

    /** @return list<DateTimeImmutable> SCH-038: the days of the week that hold a session, a skipped one too */
    public function trainingDates(): array
    {
        return array_map(fn (ScheduledSession $session) => $session->date, $this->result->scheduled);
    }

    /** TBL-031: the session with its length and intensity, Light in a deload week. */
    public function workout(ScheduledSession $session): WeekWorkout
    {
        $sets = array_sum(array_map(fn ($slot) => $slot->sets, $session->session->slots));

        return new WeekWorkout(
            $session->session, $session->time, $sets,
            EnergyRules::sessionIntensity($sets, (float) $session->time->lengthMinutes, $this->result->isDeload),
        );
    }

    /** @return list<TrainingDay> */
    private static function trainingDays(TrainSnapshot $snapshot): array
    {
        return array_values(array_filter(array_map(function (array $day) {
            $start = ClockTime::fromHhmm((string) ($day['start'] ?? ''));

            return $start === null ? null : new TrainingDay(
                PlanWeekDays::calendarWeekdayFromIso((int) $day['weekday']),
                new WorkoutTime($start, (int) ($day['minutes'] ?? 60)),
            );
        }, $snapshot->profile->training_days ?? [])));
    }
}
