<?php

use App\Domain\Model\ExerciseSlot;
use App\Domain\Model\Mesocycle;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Plan;
use App\Domain\Model\ReviewDayChange;
use App\Domain\Model\Session;
use App\Domain\Placement\ClockTime;
use App\Domain\Placement\ScheduledSession;
use App\Domain\Placement\TrainingDay;
use App\Domain\Placement\WorkoutTime;
use App\Domain\Plan\PlanWeekGrid;
use App\Domain\Progression\Deload;
use App\Domain\WeekBuilder\AwayWeek\Training;
use App\Domain\WeekBuilder\DaySessionPlan;
use App\Domain\WeekBuilder\WeekSessions;
use App\Domain\WeekBuilder\WeekSessions\LoggedDay;
use App\Domain\WeekBuilder\WeekSessions\Result;
use App\Domain\WeekBuilder\WeekSessions\SessionRow;
use App\Domain\WeekBuilder\WorkoutSessionRecord;
use Tests\Unit\Domain\Plan\PlanFixtures;

// The same tests as rp-clone's WeekSessionsTests (pin 2aa1edeb), one for one: the sessions a plan week puts on its
// dates (PLAN-008, PLAN-022, SCH-040, SCH-060), the rule WeekBuilder and the Today screen share.

/** A September 2026 day, UTC. */
function weekSessionsSept(int $day, int $hour = 0, int $minute = 0): DateTimeImmutable
{
    return PlanFixtures::date(2026, 9, $day, $hour, $minute);
}

/** @return list<TrainingDay> Monday, Wednesday and Friday at 17:30 for 60 minutes */
function weekSessionsTrainingDays(): array
{
    return array_map(fn (int $weekday) => new TrainingDay($weekday, new WorkoutTime(ClockTime::at(17, 30), 60)), [2, 4, 6]);
}

/** @param  list<string>  $names */
function weekSessionsMesocycle(array $names = ['A', 'B', 'C'], int $weeks = 3, ?DateTimeImmutable $lastDay = null): Mesocycle
{
    $sessions = array_map(fn (string $name) => new Session($name, $name, [new ExerciseSlot('lift', 'Lift', MuscleGroup::Chest, 4)]), $names);

    return new Mesocycle(id: 'M', phase: PhaseType::Maintenance, weeks: $weeks, sessions: $sessions, musclePriorities: [], lastDay: $lastDay);
}

/** Plan from Thursday 10 September, review day Monday. */
function weekSessionsGrid(): PlanWeekGrid
{
    return new PlanWeekGrid(weekSessionsSept(10), 2);
}

/**
 * @param  list<LoggedDay>  $logged
 * @param  list<int>  $pausedWeeks
 * @param  list<SessionRow>  $rows
 */
function weekSessionsDayPlan(
    int $day,
    ?Training $away = null,
    bool $flagged = false,
    ?Mesocycle $mesocycle = null,
    array $logged = [],
    array $pausedWeeks = [],
    array $rows = [],
): ?DaySessionPlan {
    return WeekSessions::plan(
        on: weekSessionsSept($day), grid: weekSessionsGrid(), mesocycle: $mesocycle ?? weekSessionsMesocycle(), mesocycleStartWeek: 0,
        trainingDays: weekSessionsTrainingDays(), awayTraining: $away, flaggedDeload: $flagged, logged: $logged,
        pausedWeeks: $pausedWeeks, rows: $rows,
    );
}

function weekSessionsName(?DaySessionPlan $plan): ?string
{
    return $plan?->session?->session->name;
}

it('PLAN-008 the short first week places its sessions from the plan start', function () {
    expect(weekSessionsDayPlan(9))->toBeNull()
        ->and(weekSessionsDayPlan(10)?->session)->toBeNull()
        ->and(weekSessionsName(weekSessionsDayPlan(11)))->toBe('A')
        ->and(weekSessionsDayPlan(11)?->sessionNumber)->toBe(1)
        ->and(weekSessionsDayPlan(11)?->weekIndex)->toBe(0)
        // The next week starts on Monday and holds all three.
        ->and(array_map(fn (int $day) => weekSessionsName(weekSessionsDayPlan($day)), [14, 16, 18]))->toBe(['A', 'B', 'C'])
        ->and(weekSessionsDayPlan(14)?->mesocycleWeek)->toBe(1);
});

it('PLAN-022 the last mesocycle week and a flagged week are deloads', function () {
    expect(weekSessionsDayPlan(14)?->isDeload)->toBeFalse()
        ->and(weekSessionsDayPlan(14, flagged: true)?->isDeload)->toBeTrue()
        // Week index 2 is the mesocycle's last.
        ->and(weekSessionsDayPlan(21)?->isDeload)->toBeTrue()
        ->and(weekSessionsDayPlan(21)?->session?->session->slots[0]->sets)
        ->toBe(Deload::session(weekSessionsMesocycle()->sessions[0])->slots[0]->sets);
});

it('SCH-060 away weeks pause or deload training', function () {
    expect(weekSessionsDayPlan(16, away: Training::Pause)?->session)->toBeNull()
        ->and(weekSessionsDayPlan(16, away: Training::Pause)?->isPaused)->toBeTrue()
        ->and(weekSessionsDayPlan(16, away: Training::Pause)?->isDeload)->toBeFalse()
        ->and(weekSessionsDayPlan(16, away: Training::Deload)?->isDeload)->toBeTrue()
        ->and(weekSessionsDayPlan(16, away: Training::Deload)?->session)->not->toBeNull();
});

it('SCH-060 a paused week does not count as a mesocycle week', function () {
    // Plan week 1 (from Monday 14 September) is paused: the 3-week mesocycle resumes where it stopped.
    $plan = fn (int $day) => weekSessionsDayPlan($day, away: $day >= 14 && $day < 21 ? Training::Pause : null, pausedWeeks: [1]);

    expect($plan(16)?->isPaused)->toBeTrue()
        ->and($plan(16)?->session)->toBeNull()
        ->and($plan(23)?->mesocycleWeek)->toBe(1)
        ->and($plan(23)?->isDeload)->toBeFalse()
        ->and($plan(30)?->mesocycleWeek)->toBe(2)
        ->and($plan(30)?->isDeload)->toBeTrue()
        ->and($plan(37)?->mesocycleWeek)->toBeNull(); // 7 October
});

it('PLAN-008 weeks outside the mesocycle have no sessions', function () {
    expect(weekSessionsDayPlan(28)?->session)->toBeNull()
        ->and(weekSessionsDayPlan(28)?->mesocycleWeek)->toBeNull()
        ->and(weekSessionsDayPlan(28)?->isDeload)->toBeFalse();
});

// MESO-043 an ended mesocycle

function weekSessionsEndedPlan(int $day, int $lastDay): ?DaySessionPlan
{
    return weekSessionsDayPlan($day, mesocycle: weekSessionsMesocycle(lastDay: weekSessionsSept($lastDay)));
}

it('MESO-043 sessions after the last day are not planned and earlier ones stay', function () {
    // Week 2 (from Monday 14 September) would hold A, B and C on 14, 16 and 18.
    expect(array_map(fn (int $day) => weekSessionsName(weekSessionsEndedPlan($day, lastDay: 16)), [14, 16, 18]))->toBe(['A', 'B', null])
        ->and(weekSessionsEndedPlan(16, lastDay: 16)?->sessionNumber)->toBe(2);
});

it('MESO-043 the deload never runs when the mesocycle ended before its week', function () {
    $after = weekSessionsEndedPlan(21, lastDay: 18);

    expect($after?->session)->toBeNull()
        ->and($after?->mesocycleWeek)->toBeNull()
        ->and($after?->isDeload)->toBeFalse();
});

it('MESO-043 a mesocycle ended during the deload week still deloads until then', function () {
    // Ended on the Tuesday of the deload week: its Monday session runs, deloaded.
    $monday = weekSessionsEndedPlan(21, lastDay: 22);

    expect($monday?->isDeload)->toBeTrue()
        ->and(weekSessionsName($monday))->toBe('A')
        ->and(weekSessionsEndedPlan(23, lastDay: 22)?->session)->toBeNull();
});

it('MESO-043 a logged session stays on the last day and the others after it go', function () {
    $ended = weekSessionsMesocycle(lastDay: weekSessionsSept(15));
    // Week 2 holds A, B and C on 14, 16 and 18; B (day 2 of week 2) was logged.
    $logged = [new LoggedDay(week: 2, day: 2)];
    $names = array_map(fn (int $day) => weekSessionsName(weekSessionsDayPlan($day, mesocycle: $ended, logged: $logged)), [14, 16, 18]);

    expect($names)->toBe(['A', 'B', null]);
});

it('MESO-043 a session logged in a week after the last day keeps that week', function () {
    // Week 2 starts on the 14th, after the last day; its day 1 was logged.
    $plan = weekSessionsDayPlan(14, mesocycle: weekSessionsMesocycle(lastDay: weekSessionsSept(13)), logged: [new LoggedDay(week: 2, day: 1)]);

    expect(weekSessionsName($plan))->toBe('A')
        ->and($plan?->mesocycleWeek)->toBe(1);
});

// A mesocycle that was not ended

it('PLAN-008 a stretched transition week keeps all its sessions when the mesocycle was not ended', function () {
    // The review day moves from Monday to Thursday; a cut that would leave the transition week 1 to 3 days
    // lengthens it instead, so one plan week runs more than 7 days (PLAN-008).
    $plan = new Plan(id: 'plan', start: weekSessionsSept(10), reviewWeekday: 2, phases: [], reviewDayChanges: [new ReviewDayChange(weekSessionsSept(14), 5)]);
    $stretched = PlanWeekGrid::forPlan($plan);
    $index = array_values(array_filter(range(0, 3), fn (int $week) => $stretched->lengthInDays($week) > 7))[0] ?? null;
    expect($index)->not->toBeNull();
    $week = $stretched->planWeek($index);
    // Every weekday trains, so each date of the week takes one session.
    $everyDay = array_map(fn (int $weekday) => new TrainingDay($weekday, new WorkoutTime(ClockTime::at(17, 30), 60)), range(1, 7));
    $meso = weekSessionsMesocycle(array_map(fn (int $n) => "S{$n}", range(0, $week->dayCount - 1)), weeks: 4);

    $result = WeekSessions::build(planWeek: $week, week: $index, mesocycle: $meso, mesocycleStartWeek: 0, trainingDays: $everyDay);

    expect($result->scheduled)->toHaveCount($week->dayCount);
});

// SCH-050 to SCH-057 a session's stored row

it('SCH-051 a row\'s planned start moves its session to that day and time', function () {
    // Week 2 holds A, B and C on 14, 16 and 18; B (day 2) moved to Tuesday 15 at 07:00.
    $rows = [new SessionRow(week: 2, day: 2, start: weekSessionsSept(15, 7), isSkipped: false)];
    $moved = weekSessionsDayPlan(15, rows: $rows);

    expect(weekSessionsDayPlan(16, rows: $rows)?->session)->toBeNull()
        ->and(weekSessionsName($moved))->toBe('B')
        ->and($moved?->sessionNumber)->toBe(2)
        ->and($moved?->session?->time->start)->toEqual(ClockTime::at(7))
        ->and($moved?->session?->date)->toEqual(weekSessionsSept(15))
        // The others keep their weekdays, and a row for another week moves nothing here.
        ->and(weekSessionsName(weekSessionsDayPlan(14, rows: $rows)))->toBe('A')
        ->and(weekSessionsName(weekSessionsDayPlan(16, rows: [new SessionRow(week: 3, day: 2, start: weekSessionsSept(22, 7), isSkipped: false)])))->toBe('B');
});

it('SCH-052 a row\'s start on the same day changes only the time', function () {
    $plan = weekSessionsDayPlan(16, rows: [new SessionRow(week: 2, day: 2, start: weekSessionsSept(16, 18, 45), isSkipped: false)]);

    expect(weekSessionsName($plan))->toBe('B')
        ->and($plan?->session?->time->start)->toEqual(ClockTime::at(18, 45));
});

it('SCH-057 a row outside its plan week leaves the session on its weekday', function () {
    $rows = [new SessionRow(week: 2, day: 2, start: weekSessionsSept(22, 7), isSkipped: false)];

    expect(weekSessionsName(weekSessionsDayPlan(16, rows: $rows)))->toBe('B')
        ->and(weekSessionsDayPlan(16, rows: $rows)?->session?->time->start)->toEqual(ClockTime::at(17, 30));
});

it('SCH-053 a skipped row keeps its session marked skipped', function () {
    $rows = [new SessionRow(week: 2, day: 2, start: null, isSkipped: true)];

    expect(weekSessionsName(weekSessionsDayPlan(16, rows: $rows)))->toBe('B')
        ->and(weekSessionsDayPlan(16, rows: $rows)?->session?->isSkipped)->toBeTrue()
        ->and(weekSessionsDayPlan(16, rows: [])?->session?->isSkipped)->toBeFalse();
});

it('SCH-040 rows are read from records as actual start then planned start', function () {
    $records = [
        new WorkoutSessionRecord(id: 'a', mesocycleId: 'M', week: 2, day: 1, plannedStartAt: weekSessionsSept(14, 17), actualStartAt: weekSessionsSept(14, 18)),
        new WorkoutSessionRecord(id: 'b', mesocycleId: 'M', week: 2, day: 2, plannedStartAt: weekSessionsSept(15, 7)),
        new WorkoutSessionRecord(id: 'c', mesocycleId: 'other', week: 2, day: 3, plannedStartAt: weekSessionsSept(17, 7)),
        new WorkoutSessionRecord(id: 'd', mesocycleId: 'M', week: 2, day: null, plannedStartAt: weekSessionsSept(17, 7)),
        new WorkoutSessionRecord(id: 'e', mesocycleId: 'm', week: 1, day: 3, isSkipped: true),
    ];

    expect(SessionRow::rows($records, 'M'))->toEqual([
        new SessionRow(week: 2, day: 1, start: weekSessionsSept(14, 18), isSkipped: false),
        new SessionRow(week: 2, day: 2, start: weekSessionsSept(15, 7), isSkipped: false),
        new SessionRow(week: 1, day: 3, start: null, isSkipped: true),
    ]);
});

// SCH-039 more sessions than training days

/** @param  list<SessionRow>  $rows */
function weekSessionsFourSessionWeek(array $rows): Result
{
    // Week 2 runs Monday 14 to Sunday 20 September; the user trains three days of it.
    return WeekSessions::build(
        planWeek: weekSessionsGrid()->planWeek(1), week: 1, mesocycle: weekSessionsMesocycle(['A', 'B', 'C', 'D']),
        mesocycleStartWeek: 0, trainingDays: weekSessionsTrainingDays(), rows: $rows,
    );
}

/** @return list<string> */
function weekSessionsScheduledNames(Result $result): array
{
    return array_map(fn (ScheduledSession $item) => $item->session->name, $result->scheduled);
}

it('SCH-039 a session without a row is not placed when the week has more sessions than training days', function () {
    expect(weekSessionsScheduledNames(weekSessionsFourSessionWeek([])))->toBe(['A', 'B', 'C']);
});

it('SCH-039 a session with a row in this week is placed even when the week has more sessions than training days', function () {
    // D has a row on Sunday 20 at 09:30, two training weekdays after the three are used up.
    $result = weekSessionsFourSessionWeek([new SessionRow(week: 2, day: 4, start: weekSessionsSept(20, 9, 30), isSkipped: false)]);

    expect(weekSessionsScheduledNames($result))->toBe(['A', 'B', 'C', 'D'])
        ->and(array_map(fn (ScheduledSession $item) => $item->date, $result->scheduled))->toEqual([weekSessionsSept(14), weekSessionsSept(16), weekSessionsSept(18), weekSessionsSept(20)])
        ->and($result->scheduled[3]->time->start)->toEqual(ClockTime::at(9, 30))
        ->and($result->scheduled[3]->time->lengthMinutes)->toBe(60)
        // The others are untouched, so a session's number is still its place in the list.
        ->and($result->scheduled[0]->time->start)->toEqual(ClockTime::at(17, 30));
});

it('SCH-039 a row outside this week does not place a session the training days dropped', function () {
    expect(weekSessionsScheduledNames(weekSessionsFourSessionWeek([new SessionRow(week: 2, day: 4, start: weekSessionsSept(22, 9), isSkipped: false)])))
        ->toBe(['A', 'B', 'C']);
});

// Port decisions the Swift suite cannot cover

it('SCH-051 a row\'s start is read in the time zone of the plan', function () {
    // A Brisbane plan (UTC+10) from Thursday 10 September; B (day 2 of week 2) moved to 21:00 UTC on Monday 14,
    // which is 07:00 on Tuesday 15 in Brisbane.
    $brisbane = fn (int $day) => PlanFixtures::date(2026, 9, $day, zone: 'Australia/Brisbane');
    $grid = new PlanWeekGrid($brisbane(10), 2);
    $rows = [new SessionRow(week: 2, day: 2, start: PlanFixtures::date(2026, 9, 14, 21), isSkipped: false)];
    $plan = fn (int $day) => WeekSessions::plan(
        on: $brisbane($day), grid: $grid, mesocycle: weekSessionsMesocycle(), mesocycleStartWeek: 0,
        trainingDays: weekSessionsTrainingDays(), rows: $rows,
    );

    expect(weekSessionsName($plan(15)))->toBe('B')
        ->and($plan(15)?->session?->date)->toEqual($brisbane(15))
        ->and($plan(15)?->session?->time->start)->toEqual(ClockTime::at(7))
        ->and($plan(14)?->session?->session->name)->toBe('A')
        ->and($plan(16)?->session)->toBeNull();

    // The date asked about is read in the plan's zone too: 21:00 UTC on Monday 14 is Brisbane's Tuesday 15.
    $atUtc = WeekSessions::plan(
        on: PlanFixtures::date(2026, 9, 14, 21), grid: $grid, mesocycle: weekSessionsMesocycle(), mesocycleStartWeek: 0,
        trainingDays: weekSessionsTrainingDays(), rows: $rows,
    );
    expect(weekSessionsName($atUtc))->toBe('B');
});

it('SCH-039 a session placed by its row alone takes the first training day\'s length', function () {
    $days = array_map(fn (int $weekday) => new TrainingDay($weekday, new WorkoutTime(ClockTime::at(17, 30), 45)), [2, 4, 6]);
    $result = WeekSessions::build(
        planWeek: weekSessionsGrid()->planWeek(1), week: 1, mesocycle: weekSessionsMesocycle(['A', 'B', 'C', 'D']),
        mesocycleStartWeek: 0, trainingDays: $days,
        rows: [new SessionRow(week: 2, day: 4, start: weekSessionsSept(20, 9, 30), isSkipped: false)],
    );

    expect($result->scheduled[3]->time->lengthMinutes)->toBe(45);
});
