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
function sept(int $day, int $hour = 0, int $minute = 0): DateTimeImmutable
{
    return PlanFixtures::date(2026, 9, $day, $hour, $minute);
}

/** @return list<TrainingDay> Monday, Wednesday and Friday at 17:30 for 60 minutes */
function sessionTrainingDays(): array
{
    return array_map(fn (int $weekday) => new TrainingDay($weekday, new WorkoutTime(ClockTime::at(17, 30), 60)), [2, 4, 6]);
}

/** @param  list<string>  $names */
function sessionMesocycle(array $names = ['A', 'B', 'C'], int $weeks = 3, ?DateTimeImmutable $lastDay = null): Mesocycle
{
    $sessions = array_map(fn (string $name) => new Session($name, $name, [new ExerciseSlot('lift', 'Lift', MuscleGroup::Chest, 4)]), $names);

    return new Mesocycle(id: 'M', phase: PhaseType::Maintenance, weeks: $weeks, sessions: $sessions, musclePriorities: [], lastDay: $lastDay);
}

/** Plan from Thursday 10 September, review day Monday. */
function sessionGrid(): PlanWeekGrid
{
    return new PlanWeekGrid(sept(10), 2);
}

/**
 * @param  list<LoggedDay>  $logged
 * @param  list<int>  $pausedWeeks
 * @param  list<SessionRow>  $rows
 */
function dayPlan(
    int $day,
    ?Training $away = null,
    bool $flagged = false,
    ?Mesocycle $mesocycle = null,
    array $logged = [],
    array $pausedWeeks = [],
    array $rows = [],
): ?DaySessionPlan {
    return WeekSessions::plan(
        on: sept($day), grid: sessionGrid(), mesocycle: $mesocycle ?? sessionMesocycle(), mesocycleStartWeek: 0,
        trainingDays: sessionTrainingDays(), awayTraining: $away, flaggedDeload: $flagged, logged: $logged,
        pausedWeeks: $pausedWeeks, rows: $rows,
    );
}

function sessionName(?DaySessionPlan $plan): ?string
{
    return $plan?->session?->session->name;
}

it('PLAN-008 the short first week places its sessions from the plan start', function () {
    expect(dayPlan(9))->toBeNull()
        ->and(dayPlan(10)?->session)->toBeNull()
        ->and(sessionName(dayPlan(11)))->toBe('A')
        ->and(dayPlan(11)?->sessionNumber)->toBe(1)
        ->and(dayPlan(11)?->weekIndex)->toBe(0)
        // The next week starts on Monday and holds all three.
        ->and(array_map(fn (int $day) => sessionName(dayPlan($day)), [14, 16, 18]))->toBe(['A', 'B', 'C'])
        ->and(dayPlan(14)?->mesocycleWeek)->toBe(1);
});

it('PLAN-022 the last mesocycle week and a flagged week are deloads', function () {
    expect(dayPlan(14)?->isDeload)->toBeFalse()
        ->and(dayPlan(14, flagged: true)?->isDeload)->toBeTrue()
        // Week index 2 is the mesocycle's last.
        ->and(dayPlan(21)?->isDeload)->toBeTrue()
        ->and(dayPlan(21)?->session?->session->slots[0]->sets)
        ->toBe(Deload::session(sessionMesocycle()->sessions[0])->slots[0]->sets);
});

it('SCH-060 away weeks pause or deload training', function () {
    expect(dayPlan(16, away: Training::Pause)?->session)->toBeNull()
        ->and(dayPlan(16, away: Training::Pause)?->isPaused)->toBeTrue()
        ->and(dayPlan(16, away: Training::Pause)?->isDeload)->toBeFalse()
        ->and(dayPlan(16, away: Training::Deload)?->isDeload)->toBeTrue()
        ->and(dayPlan(16, away: Training::Deload)?->session)->not->toBeNull();
});

it('SCH-060 a paused week does not count as a mesocycle week', function () {
    // Plan week 1 (from Monday 14 September) is paused: the 3-week mesocycle resumes where it stopped.
    $plan = fn (int $day) => dayPlan($day, away: $day >= 14 && $day < 21 ? Training::Pause : null, pausedWeeks: [1]);

    expect($plan(16)?->isPaused)->toBeTrue()
        ->and($plan(16)?->session)->toBeNull()
        ->and($plan(23)?->mesocycleWeek)->toBe(1)
        ->and($plan(23)?->isDeload)->toBeFalse()
        ->and($plan(30)?->mesocycleWeek)->toBe(2)
        ->and($plan(30)?->isDeload)->toBeTrue()
        ->and($plan(37)?->mesocycleWeek)->toBeNull(); // 7 October
});

it('PLAN-008 weeks outside the mesocycle have no sessions', function () {
    expect(dayPlan(28)?->session)->toBeNull()
        ->and(dayPlan(28)?->mesocycleWeek)->toBeNull()
        ->and(dayPlan(28)?->isDeload)->toBeFalse();
});

// MESO-043 an ended mesocycle

function endedPlan(int $day, int $lastDay): ?DaySessionPlan
{
    return dayPlan($day, mesocycle: sessionMesocycle(lastDay: sept($lastDay)));
}

it('MESO-043 sessions after the last day are not planned and earlier ones stay', function () {
    // Week 2 (from Monday 14 September) would hold A, B and C on 14, 16 and 18.
    expect(array_map(fn (int $day) => sessionName(endedPlan($day, lastDay: 16)), [14, 16, 18]))->toBe(['A', 'B', null])
        ->and(endedPlan(16, lastDay: 16)?->sessionNumber)->toBe(2);
});

it('MESO-043 the deload never runs when the mesocycle ended before its week', function () {
    $after = endedPlan(21, lastDay: 18);

    expect($after?->session)->toBeNull()
        ->and($after?->mesocycleWeek)->toBeNull()
        ->and($after?->isDeload)->toBeFalse();
});

it('MESO-043 a mesocycle ended during the deload week still deloads until then', function () {
    // Ended on the Tuesday of the deload week: its Monday session runs, deloaded.
    $monday = endedPlan(21, lastDay: 22);

    expect($monday?->isDeload)->toBeTrue()
        ->and(sessionName($monday))->toBe('A')
        ->and(endedPlan(23, lastDay: 22)?->session)->toBeNull();
});

it('MESO-043 a logged session stays on the last day and the others after it go', function () {
    $ended = sessionMesocycle(lastDay: sept(15));
    // Week 2 holds A, B and C on 14, 16 and 18; B (day 2 of week 2) was logged.
    $logged = [new LoggedDay(week: 2, day: 2)];
    $names = array_map(fn (int $day) => sessionName(dayPlan($day, mesocycle: $ended, logged: $logged)), [14, 16, 18]);

    expect($names)->toBe(['A', 'B', null]);
});

it('MESO-043 a session logged in a week after the last day keeps that week', function () {
    // Week 2 starts on the 14th, after the last day; its day 1 was logged.
    $plan = dayPlan(14, mesocycle: sessionMesocycle(lastDay: sept(13)), logged: [new LoggedDay(week: 2, day: 1)]);

    expect(sessionName($plan))->toBe('A')
        ->and($plan?->mesocycleWeek)->toBe(1);
});

// A mesocycle that was not ended

it('PLAN-008 a stretched transition week keeps all its sessions when the mesocycle was not ended', function () {
    // The review day moves from Monday to Thursday; a cut that would leave the transition week 1 to 3 days
    // lengthens it instead, so one plan week runs more than 7 days (PLAN-008).
    $plan = new Plan(id: 'plan', start: sept(10), reviewWeekday: 2, phases: [], reviewDayChanges: [new ReviewDayChange(sept(14), 5)]);
    $stretched = PlanWeekGrid::forPlan($plan);
    $index = array_values(array_filter(range(0, 3), fn (int $week) => $stretched->lengthInDays($week) > 7))[0] ?? null;
    expect($index)->not->toBeNull();
    $week = $stretched->planWeek($index);
    // Every weekday trains, so each date of the week takes one session.
    $everyDay = array_map(fn (int $weekday) => new TrainingDay($weekday, new WorkoutTime(ClockTime::at(17, 30), 60)), range(1, 7));
    $meso = sessionMesocycle(array_map(fn (int $n) => "S{$n}", range(0, $week->dayCount - 1)), weeks: 4);

    $result = WeekSessions::build(planWeek: $week, week: $index, mesocycle: $meso, mesocycleStartWeek: 0, trainingDays: $everyDay);

    expect($result->scheduled)->toHaveCount($week->dayCount);
});

// SCH-050 to SCH-057 a session's stored row

it('SCH-051 a row\'s planned start moves its session to that day and time', function () {
    // Week 2 holds A, B and C on 14, 16 and 18; B (day 2) moved to Tuesday 15 at 07:00.
    $rows = [new SessionRow(week: 2, day: 2, start: sept(15, 7), isSkipped: false)];
    $moved = dayPlan(15, rows: $rows);

    expect(dayPlan(16, rows: $rows)?->session)->toBeNull()
        ->and(sessionName($moved))->toBe('B')
        ->and($moved?->sessionNumber)->toBe(2)
        ->and($moved?->session?->time->start)->toEqual(ClockTime::at(7))
        ->and($moved?->session?->date)->toEqual(sept(15))
        // The others keep their weekdays, and a row for another week moves nothing here.
        ->and(sessionName(dayPlan(14, rows: $rows)))->toBe('A')
        ->and(sessionName(dayPlan(16, rows: [new SessionRow(week: 3, day: 2, start: sept(22, 7), isSkipped: false)])))->toBe('B');
});

it('SCH-052 a row\'s start on the same day changes only the time', function () {
    $plan = dayPlan(16, rows: [new SessionRow(week: 2, day: 2, start: sept(16, 18, 45), isSkipped: false)]);

    expect(sessionName($plan))->toBe('B')
        ->and($plan?->session?->time->start)->toEqual(ClockTime::at(18, 45));
});

it('SCH-057 a row outside its plan week leaves the session on its weekday', function () {
    $rows = [new SessionRow(week: 2, day: 2, start: sept(22, 7), isSkipped: false)];

    expect(sessionName(dayPlan(16, rows: $rows)))->toBe('B')
        ->and(dayPlan(16, rows: $rows)?->session?->time->start)->toEqual(ClockTime::at(17, 30));
});

it('SCH-053 a skipped row keeps its session marked skipped', function () {
    $rows = [new SessionRow(week: 2, day: 2, start: null, isSkipped: true)];

    expect(sessionName(dayPlan(16, rows: $rows)))->toBe('B')
        ->and(dayPlan(16, rows: $rows)?->session?->isSkipped)->toBeTrue()
        ->and(dayPlan(16, rows: [])?->session?->isSkipped)->toBeFalse();
});

it('SCH-040 rows are read from records as actual start then planned start', function () {
    $records = [
        new WorkoutSessionRecord(id: 'a', mesocycleId: 'M', week: 2, day: 1, plannedStartAt: sept(14, 17), actualStartAt: sept(14, 18)),
        new WorkoutSessionRecord(id: 'b', mesocycleId: 'M', week: 2, day: 2, plannedStartAt: sept(15, 7)),
        new WorkoutSessionRecord(id: 'c', mesocycleId: 'other', week: 2, day: 3, plannedStartAt: sept(17, 7)),
        new WorkoutSessionRecord(id: 'd', mesocycleId: 'M', week: 2, day: null, plannedStartAt: sept(17, 7)),
        new WorkoutSessionRecord(id: 'e', mesocycleId: 'm', week: 1, day: 3, isSkipped: true),
    ];

    expect(SessionRow::rows($records, 'M'))->toEqual([
        new SessionRow(week: 2, day: 1, start: sept(14, 18), isSkipped: false),
        new SessionRow(week: 2, day: 2, start: sept(15, 7), isSkipped: false),
        new SessionRow(week: 1, day: 3, start: null, isSkipped: true),
    ]);
});

// SCH-039 more sessions than training days

/** @param  list<SessionRow>  $rows */
function fourSessionWeek(array $rows): Result
{
    // Week 2 runs Monday 14 to Sunday 20 September; the user trains three days of it.
    return WeekSessions::build(
        planWeek: sessionGrid()->planWeek(1), week: 1, mesocycle: sessionMesocycle(['A', 'B', 'C', 'D']),
        mesocycleStartWeek: 0, trainingDays: sessionTrainingDays(), rows: $rows,
    );
}

/** @return list<string> */
function scheduledNames(Result $result): array
{
    return array_map(fn (ScheduledSession $item) => $item->session->name, $result->scheduled);
}

it('SCH-039 a session without a row is not placed when the week has more sessions than training days', function () {
    expect(scheduledNames(fourSessionWeek([])))->toBe(['A', 'B', 'C']);
});

it('SCH-039 a session with a row in this week is placed even when the week has more sessions than training days', function () {
    // D has a row on Sunday 20 at 09:30, two training weekdays after the three are used up.
    $result = fourSessionWeek([new SessionRow(week: 2, day: 4, start: sept(20, 9, 30), isSkipped: false)]);

    expect(scheduledNames($result))->toBe(['A', 'B', 'C', 'D'])
        ->and(array_map(fn (ScheduledSession $item) => $item->date, $result->scheduled))->toEqual([sept(14), sept(16), sept(18), sept(20)])
        ->and($result->scheduled[3]->time->start)->toEqual(ClockTime::at(9, 30))
        ->and($result->scheduled[3]->time->lengthMinutes)->toBe(60)
        // The others are untouched, so a session's number is still its place in the list.
        ->and($result->scheduled[0]->time->start)->toEqual(ClockTime::at(17, 30));
});

it('SCH-039 a row outside this week does not place a session the training days dropped', function () {
    expect(scheduledNames(fourSessionWeek([new SessionRow(week: 2, day: 4, start: sept(22, 9), isSkipped: false)])))
        ->toBe(['A', 'B', 'C']);
});
