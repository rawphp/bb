<?php

use App\Domain\Mesocycle\Exercise;
use App\Domain\Mesocycle\StimulusToFatigue;
use App\Domain\Model\Day;
use App\Domain\Model\DayType;
use App\Domain\Model\ExerciseSlot;
use App\Domain\Model\MealTarget;
use App\Domain\Model\Mesocycle;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\PhaseType;
use App\Domain\Model\PlanWeek;
use App\Domain\Model\Priority;
use App\Domain\Model\Session;
use App\Domain\Placement\ClockRange;
use App\Domain\Placement\ClockTime;
use App\Domain\Placement\SessionPlacement;
use App\Domain\Placement\TrainingDay;
use App\Domain\Placement\WorkoutTime;
use App\Domain\Retarget\RetargetContext;
use App\Domain\Retarget\RetargetDay;
use App\Domain\Retarget\RetargetMeal;
use App\Domain\Retarget\TrainingWeekdays;
use App\Domain\Retarget\WeekdayChange;
use App\Domain\Retarget\WeekdayChoice;
use App\Domain\WeekBuilder\WeekMealKind;
use Tests\Unit\Domain\Plan\PlanFixtures;
use Tests\Unit\Domain\Retarget\RetargetFixtures as F;

// The same tests as rp-clone's WeekdayChangeTests (pin 2aa1edeb), one for one.

// A 6-week fat-loss mesocycle (the last is the deload) of four sessions on Monday, Wednesday, Friday and Saturday,
// from Monday 2026-01-05 (UTC). Weekly sets: chest 7, back 9, quads 10.

function wdSlot(string $id, string $name, MuscleGroup $muscle, int $sets): ExerciseSlot
{
    return new ExerciseSlot($id, $name, $muscle, $sets);
}

/**
 * @param  list<int>  $weekdays
 * @return list<TrainingDay>
 */
function wdDays(array $weekdays): array
{
    return array_map(fn (int $weekday) => new TrainingDay($weekday, new WorkoutTime(ClockTime::at(17, 30), 0)), $weekdays);
}

/** @return list<Session> */
function wdSessions(): array
{
    return [
        new Session('a', 'A', [wdSlot('a1', 'Bench press', MuscleGroup::Chest, 4), wdSlot('a2', 'Squat', MuscleGroup::Quads, 4)]),
        new Session('b', 'B', [wdSlot('b1', 'Row', MuscleGroup::Back, 4), wdSlot('b2', 'Squat', MuscleGroup::Quads, 3)]),
        new Session('c', 'C', [wdSlot('c1', 'Bench press', MuscleGroup::Chest, 3), wdSlot('c2', 'Row', MuscleGroup::Back, 3)]),
        new Session('d', 'D', [wdSlot('d1', 'Squat', MuscleGroup::Quads, 3), wdSlot('d2', 'Row', MuscleGroup::Back, 2)]),
    ];
}

/** @return array<string, Priority> */
function wdPriorities(): array
{
    return ['chest' => Priority::Grow, 'back' => Priority::Grow, 'quads' => Priority::Emphasize];
}

function wdMesocycle(): Mesocycle
{
    return new Mesocycle('meso', PhaseType::FatLoss, 6, wdSessions(), wdPriorities());
}

/** @param  list<Session>  $sessions */
function wdWithSessions(Mesocycle $meso, array $sessions): Mesocycle
{
    return new Mesocycle(
        $meso->id, $meso->phase, $meso->weeks, $sessions, $meso->musclePriorities, $meso->isResensitization,
        $meso->earlierWeeks, $meso->laterWeeks, $meso->lastDay,
    );
}

/** The ids the Meso Builder gets for new sessions and slots. */
function wdNewId(): Closure
{
    return F::newId();
}

/**
 * @param  list<Session>  $sessions
 * @return list<string>
 */
function wdNames(array $sessions): array
{
    return array_map(fn (Session $s) => $s->name, $sessions);
}

/** @param  list<Session>  $sessions */
function wdWeeklySets(MuscleGroup $muscle, array $sessions): int
{
    $sets = 0;
    foreach ($sessions as $session) {
        foreach ($session->slots as $slot) {
            if ($slot->muscle === $muscle) {
                $sets += $slot->sets;
            }
        }
    }

    return $sets;
}

/** @param  list<Session>  $sessions */
function wdFrequency(MuscleGroup $muscle, array $sessions): int
{
    return count(array_filter($sessions, fn (Session $s) => array_filter($s->slots, fn (ExerciseSlot $slot) => $slot->muscle === $muscle) !== []));
}

/**
 * @param  list<Session>  $sessions
 * @return list<int> the Swift weekday (1 = Sunday ... 7 = Saturday) of each placed session
 */
function wdWeekdays(array $sessions, array $trainingDays, PlanWeek $week): array
{
    return array_map(fn ($placed) => (int) $placed->date->format('w') + 1, SessionPlacement::place($sessions, $trainingDays, $week));
}

// Same number of days

it('SCH-039 SCH-040 same number of days re-maps the sessions in order onto the new weekdays', function () {
    // Tuesday, Thursday, Saturday, Sunday.
    $new = wdDays([3, 5, 7, 1]);
    $change = TrainingWeekdays::change(wdMesocycle(), wdDays([2, 4, 6, 7]), $new, fromWeek: 2);
    expect($change)->toEqual(WeekdayChange::remap());

    // The mesocycle is unchanged: next week's sessions go, in order, onto the new weekdays.
    $next = new PlanWeek(PlanFixtures::date(2026, 1, 19));
    $placed = SessionPlacement::place(wdMesocycle()->sessionsInWeek(2), $new, $next);
    expect(wdNames(array_map(fn ($p) => $p->session, $placed)))->toBe(['A', 'B', 'C', 'D'])
        ->and(wdWeekdays(wdMesocycle()->sessionsInWeek(2), $new, $next))->toBe([3, 5, 7, 1]);
});

it('a mesocycle that never changed runs its sessions every week', function () {
    foreach (range(0, 5) as $week) {
        expect(wdMesocycle()->sessionsInWeek($week))->toEqual(wdSessions());
    }
});

// Fewer days

it('SCH-039 fewer days offers restructure and keep all sessions', function () {
    $change = TrainingWeekdays::change(wdMesocycle(), wdDays([2, 4, 6, 7]), wdDays([2, 4, 6]), fromWeek: 2);
    expect($change)->toEqual(WeekdayChange::choose([WeekdayChoice::restructure([]), WeekdayChoice::keepAllSessions(7)]));
});

it('SCH-039 MESO-023 restructure rebuilds the weeks from the change for the new day count', function () {
    $exercises = [
        new Exercise('Bench press', MuscleGroup::Chest, StimulusToFatigue::High, true),
        new Exercise('Row', MuscleGroup::Back, StimulusToFatigue::High, true),
        new Exercise('Squat', MuscleGroup::Quads, StimulusToFatigue::High, true),
    ];
    $result = TrainingWeekdays::restructured(wdMesocycle(), daysPerWeek: 3, fromWeek: 2, exercises: $exercises, newId: wdNewId());

    expect($result->id)->toBe(wdMesocycle()->id)
        ->and($result->weeks)->toBe(6)
        ->and($result->musclePriorities)->toEqual(wdPriorities())
        // The weeks before the change ran as they were.
        ->and($result->sessionsInWeek(0))->toEqual(wdSessions())
        ->and($result->sessionsInWeek(1))->toEqual(wdSessions());

    // From the change, the deload included: three Meso Builder days.
    $rebuilt = $result->sessionsInWeek(2);
    expect(wdNames($rebuilt))->toBe(['Day 1', 'Day 2', 'Day 3']);
    foreach (range(3, 5) as $week) {
        expect($result->sessionsInWeek($week))->toEqual($rebuilt);
    }
    foreach ([MuscleGroup::Chest, MuscleGroup::Back, MuscleGroup::Quads] as $muscle) {
        // MESO-023: at least twice a week in Fat loss, with the week's sets kept.
        expect(wdFrequency($muscle, $rebuilt))->toBeGreaterThanOrEqual(2, $muscle->value)
            ->and(wdWeeklySets($muscle, $rebuilt))->toBe(wdWeeklySets($muscle, wdSessions()), $muscle->value);
    }
    // The user's exercise for each muscle carries over; Emphasize goes first (MESO-046).
    $names = [];
    foreach ($rebuilt as $session) {
        foreach ($session->slots as $slot) {
            $names[$slot->muscle->value] ??= $slot->exerciseName;
        }
    }
    ksort($names);
    expect($names)->toBe(['back' => 'Row', 'chest' => 'Bench press', 'quads' => 'Squat']);
    foreach ($rebuilt as $session) {
        if (wdFrequency(MuscleGroup::Quads, [$session]) > 0) {
            expect($session->slots[0]->muscle)->toBe(MuscleGroup::Quads);
        }
    }
    expect(TrainingWeekdays::underTrained($result, week: 2))->toBe([]);
});

it('MESO-023 a restructure onto one day is flagged for frequency', function () {
    $result = TrainingWeekdays::restructured(wdMesocycle(), daysPerWeek: 1, fromWeek: 2, exercises: [], newId: wdNewId());
    expect($result->sessionsInWeek(2))->toHaveCount(1)
        ->and(TrainingWeekdays::underTrained($result, week: 2))->toBe([MuscleGroup::Chest, MuscleGroup::Back, MuscleGroup::Quads]);

    $change = TrainingWeekdays::change(wdMesocycle(), wdDays([2, 4, 6, 7]), wdDays([2]), fromWeek: 2);
    expect($change)->toEqual(WeekdayChange::choose([
        WeekdayChoice::restructure([MuscleGroup::Chest, MuscleGroup::Back, MuscleGroup::Quads]),
        WeekdayChoice::keepAllSessions(15),
    ]));
});

it('SCH-039 keep all sessions finishes as many as the week has days in order and runs longer', function () {
    $result = TrainingWeekdays::keepingAllSessions(wdMesocycle(), daysPerWeek: 3, fromWeek: 2);

    // Weeks 2 to 4 held 12 sessions; at 3 a week they take 4 weeks, then the deload.
    expect($result->weeks)->toBe(7)
        ->and($result->sessions)->toEqual(wdSessions())
        ->and($result->sessionsInWeek(0))->toEqual(wdSessions())
        ->and($result->sessionsInWeek(1))->toEqual(wdSessions())
        ->and(wdNames($result->sessionsInWeek(2)))->toBe(['A', 'B', 'C'])
        ->and(wdNames($result->sessionsInWeek(3)))->toBe(['D', 'A', 'B'])
        ->and(wdNames($result->sessionsInWeek(4)))->toBe(['C', 'D', 'A'])
        ->and(wdNames($result->sessionsInWeek(5)))->toBe(['B', 'C', 'D'])
        // The deload stays the last week.
        ->and($result->sessionsInWeek(6))->toEqual(wdSessions());
});

it('SCH-039 keep all sessions ends on a short week when the sessions do not divide', function () {
    $result = TrainingWeekdays::keepingAllSessions(wdMesocycle(), daysPerWeek: 3, fromWeek: 3);

    // Weeks 3 and 4 held 8 sessions: 3, 3, then 2.
    expect($result->weeks)->toBe(7)
        ->and(wdNames($result->sessionsInWeek(2)))->toBe(['A', 'B', 'C', 'D'])
        ->and(wdNames($result->sessionsInWeek(3)))->toBe(['A', 'B', 'C'])
        ->and(wdNames($result->sessionsInWeek(4)))->toBe(['D', 'A', 'B'])
        ->and(wdNames($result->sessionsInWeek(5)))->toBe(['C', 'D'])
        ->and($result->sessionsInWeek(6))->toEqual(wdSessions());
});

it('SCH-039 keep all sessions from the deload week changes nothing', function () {
    $result = TrainingWeekdays::keepingAllSessions(wdMesocycle(), daysPerWeek: 3, fromWeek: 5);
    expect($result)->toEqual(wdMesocycle());
});

it('SCH-039 a second change keeps the weeks the first one shaped', function () {
    $first = TrainingWeekdays::keepingAllSessions(wdMesocycle(), daysPerWeek: 3, fromWeek: 2);
    $second = TrainingWeekdays::keepingAllSessions($first, daysPerWeek: 2, fromWeek: 3);

    // Weeks 3 to 5 held D A B, C D A, B C D: 9 sessions at 2 a week take 5 weeks.
    expect($second->weeks)->toBe(9)
        ->and(wdNames($second->sessionsInWeek(2)))->toBe(['A', 'B', 'C'])
        ->and(wdNames($second->sessionsInWeek(3)))->toBe(['D', 'A'])
        ->and(wdNames($second->sessionsInWeek(4)))->toBe(['B', 'C'])
        ->and(wdNames($second->sessionsInWeek(7)))->toBe(['D'])
        ->and($second->sessionsInWeek(8))->toEqual(wdSessions());

    $restructured = TrainingWeekdays::restructured($first, daysPerWeek: 2, fromWeek: 4, exercises: [], newId: wdNewId());
    expect($restructured->weeks)->toBe(7)
        ->and(wdNames($restructured->sessionsInWeek(3)))->toBe(['D', 'A', 'B'])
        ->and(wdNames($restructured->sessionsInWeek(4)))->toBe(['Day 1', 'Day 2'])
        ->and(wdNames($restructured->sessionsInWeek(6)))->toBe(['Day 1', 'Day 2']);
    // The rebuilt week carries the full rotation's sets, not the partial week the change fell on.
    foreach ([MuscleGroup::Chest, MuscleGroup::Back, MuscleGroup::Quads] as $muscle) {
        expect(wdWeeklySets($muscle, $restructured->sessionsInWeek(4)))->toBe(wdWeeklySets($muscle, wdSessions()), $muscle->value);
    }
});

it('SCH-039 a restructure after a one-day keep-all keeps every muscle\'s exercise', function () {
    $first = TrainingWeekdays::keepingAllSessions(wdMesocycle(), daysPerWeek: 1, fromWeek: 2);
    expect(wdNames($first->sessionsInWeek(2)))->toBe(['A']);
    $result = TrainingWeekdays::restructured($first, daysPerWeek: 3, fromWeek: 2, exercises: [], newId: wdNewId());
    $slots = array_merge(...array_map(fn (Session $s) => $s->slots, $result->sessionsInWeek(2)));
    expect(array_filter($slots, fn (ExerciseSlot $slot) => $slot->exerciseName === '' || $slot->sets === 0))->toBe([]);
});

it('SCH-039 kept weeks follow their sessions when the sessions are reordered or edited', function () {
    $kept = TrainingWeekdays::keepingAllSessions(wdMesocycle(), daysPerWeek: 3, fromWeek: 2);
    $reversed = array_reverse($kept->sessions);
    $first = $reversed[0];
    $reversed[0] = new Session($first->id, $first->name, [
        new ExerciseSlot($first->slots[0]->id, $first->slots[0]->exerciseName, $first->slots[0]->muscle, $first->slots[0]->sets + 1),
        ...array_slice($first->slots, 1),
    ]);
    $result = wdWithSessions($kept, $reversed);
    expect(wdNames($result->sessionsInWeek(3)))->toBe(['D', 'A', 'B'])
        ->and($result->sessionsInWeek(3)[0])->toEqual($result->sessions[0]);

    // A session removed from the mesocycle leaves its weeks; nothing traps.
    $removed = wdWithSessions($result, array_slice($result->sessions, 0, -1));
    expect(wdNames($removed->sessionsInWeek(3)))->toBe(['D', 'B']);
});

// More days

it('SCH-039 more days offers the same two choices', function () {
    $change = TrainingWeekdays::change(wdMesocycle(), wdDays([2, 4, 6, 7]), wdDays([2, 3, 4, 6, 7]), fromWeek: 2);
    expect($change)->toEqual(WeekdayChange::choose([WeekdayChoice::restructure([]), WeekdayChoice::keepAllSessions(6)]));
});

it('SCH-039 more days restructure rebuilds for the new day count', function () {
    $result = TrainingWeekdays::restructured(wdMesocycle(), daysPerWeek: 5, fromWeek: 2, exercises: [], newId: wdNewId());
    expect($result->weeks)->toBe(6)
        ->and($result->sessionsInWeek(1))->toEqual(wdSessions())
        ->and(wdNames($result->sessionsInWeek(2)))->toBe(['Day 1', 'Day 2', 'Day 3', 'Day 4', 'Day 5'])
        ->and(TrainingWeekdays::underTrained($result, week: 2))->toBe([]);
});

it('SCH-039 restructuring from a week past the end changes nothing', function () {
    expect(TrainingWeekdays::restructured(wdMesocycle(), daysPerWeek: 3, fromWeek: 6, exercises: [], newId: wdNewId()))
        ->toEqual(wdMesocycle());
});

it('SCH-039 more days keep all sessions leaves the extra weekdays free and the length unchanged', function () {
    $new = wdDays([2, 3, 4, 6, 7]);
    $result = TrainingWeekdays::keepingAllSessions(wdMesocycle(), daysPerWeek: 5, fromWeek: 2);
    expect($result)->toEqual(wdMesocycle());

    $next = new PlanWeek(PlanFixtures::date(2026, 1, 19));
    $placed = SessionPlacement::place($result->sessionsInWeek(2), $new, $next);
    expect(wdNames(array_map(fn ($p) => $p->session, $placed)))->toBe(['A', 'B', 'C', 'D'])
        // Saturday, the fifth training weekday, has no session.
        ->and(wdWeekdays($result->sessionsInWeek(2), $new, $next))->toBe([2, 3, 4, 6]);
});

// This plan week: a 90 kg user with five meals; the new schedule eats 08:00 to 20:00 and trains Monday at 12:00.

function wdWeekDay(DateTimeImmutable $date, bool $training, bool $checkedIn = false): RetargetDay
{
    $session = $training ? F::session() : null;
    $target = new MealTarget(kcal: 528, proteinG: 36, fatG: 16, carbsG: 60);
    $meals = [
        F::meal(7, target: $target, checkedIn: $checkedIn),
        F::meal(10, target: $target),
        F::meal(13, target: $target),
        F::meal(16, kind: $training ? WeekMealKind::PreWorkout : WeekMealKind::Regular, target: $target),
        F::meal(19, kind: $training ? WeekMealKind::PostWorkout : WeekMealKind::Regular, target: $target),
    ];
    $type = $session === null ? DayType::Rest : $session->intensity;

    return new RetargetDay(day: new Day($date, $type), session: $session, otherActivity: null, meals: $meals, target: F::dayTarget());
}

it('SCH-039 this week\'s unchecked days are re-targeted against the new schedule and checked days never change', function () {
    $monday = PlanFixtures::date(2026, 1, 5);
    $tuesday = PlanFixtures::date(2026, 1, 6);
    $wednesday = PlanFixtures::date(2026, 1, 7);
    $thursday = PlanFixtures::date(2026, 1, 8);
    $context = new RetargetContext(weightKg: 90, eatingWindow: new ClockRange(ClockTime::at(8), ClockTime::at(20)));
    $trainingDays = [
        new TrainingDay(2, new WorkoutTime(ClockTime::at(12), 0)),
        new TrainingDay(6, new WorkoutTime(ClockTime::at(12), 0)),
    ];
    $days = [
        wdWeekDay($monday, training: true),
        wdWeekDay($tuesday, training: false),
        wdWeekDay($wednesday, training: true),
        wdWeekDay($thursday, training: true, checkedIn: true),
    ];
    $result = TrainingWeekdays::retargetThisWeek($days, $trainingDays, $context, F::newId());

    // The checked day is left out, as it was.
    expect(array_map(fn (RetargetDay $d) => $d->day->date, $result->days))->toEqual([$monday, $tuesday, $wednesday])
        ->and($result->undo)->toEqual(array_slice($days, 0, 3))
        ->and($result->message)->toBe('Meals updated for your new schedule');

    // Monday keeps its session and takes the new 12:00 time: its meals are placed around it.
    $mon = $result->days[0];
    expect($mon->session->session)->toEqual($days[0]->session->session)
        ->and($mon->session->time->start)->toEqual(ClockTime::at(12));
    $pre = F::mealOfKind(WeekMealKind::PreWorkout, $mon);
    $post = F::mealOfKind(WeekMealKind::PostWorkout, $mon);
    expect($pre->time->minuteOfDay)->toBeBetween(9 * 60, 11 * 60)
        ->and($post->time->minuteOfDay)->toBeBetween(12 * 60 + 30, 13 * 60 + 30);

    // Tuesday stays a rest day; its meals move inside the new eating window.
    $tue = $result->days[1];
    expect($tue->session)->toBeNull()
        ->and($tue->day->type)->toBe(DayType::Rest)
        ->and(array_map(fn (RetargetMeal $m) => $m->time, $tue->meals))->not->toEqual(array_map(fn (RetargetMeal $m) => $m->time, $days[1]->meals));

    // Wednesday is no longer a training weekday, but this week's session stays on its day and time.
    expect($result->days[2]->session)->toEqual($days[2]->session);

    foreach ($result->days as $day) {
        foreach ($day->meals as $meal) {
            expect($meal->time->minuteOfDay)->toBeBetween(8 * 60, 20 * 60);
        }
        expect(F::kcal($day->meals))->toEqualWithDelta($day->target->kcal, 0.5)
            ->and($day->target)->toEqual(F::dayTarget());
    }
});
