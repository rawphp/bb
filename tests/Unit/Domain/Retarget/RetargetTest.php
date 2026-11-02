<?php

use App\Domain\Model\DayType;
use App\Domain\Model\MealTarget;
use App\Domain\Model\PlanWeek;
use App\Domain\Placement\ClockRange;
use App\Domain\Placement\ClockTime;
use App\Domain\Placement\WorkoutTime;
use App\Domain\Retarget\MissedDayOption;
use App\Domain\Retarget\OtherActivity;
use App\Domain\Retarget\RetargetContext;
use App\Domain\Retarget\RetargetDay;
use App\Domain\Retarget\Retargeting;
use App\Domain\Retarget\RetargetMeal;
use App\Domain\Retarget\RetargetRefusal;
use App\Domain\WeekBuilder\MealNames;
use App\Domain\WeekBuilder\WeekMealKind;
use Tests\Unit\Domain\Plan\PlanFixtures;
use Tests\Unit\Domain\Retarget\RetargetFixtures as F;

// The same tests as rp-clone's RetargetTests (pin 2aa1edeb), one for one.

// SCH-038

it('SCH-038 other activity energy adds and only the session gets peri-workout meals', function () {
    // 8 sets (30 min, light) and a 60-minute moderate activity: the day is moderate.
    $activity = new OtherActivity('Football', 60, DayType::Moderate);
    $day = F::day(activity: $activity);
    expect($day->day->type)->toBe(DayType::Moderate);

    $result = Retargeting::retime($day, ClockTime::at(12), F::context(), F::newId());
    expect($result)->not->toBeNull();
    $moved = $result->days[0];
    expect($moved->day->type)->toBe(DayType::Moderate)
        ->and($moved->otherActivity)->toEqual($activity);
    // Peri-workout meals sit around the mesocycle session at 12:00 (30 min), not the activity.
    $pre = F::mealOfKind(WeekMealKind::PreWorkout, $moved);
    $post = F::mealOfKind(WeekMealKind::PostWorkout, $moved);
    expect($pre->time->minuteOfDay)->toBeBetween(9 * 60, 11 * 60)
        ->and($post->time->minuteOfDay)->toBeBetween(12 * 60 + 30, 13 * 60 + 30);

    // A day can hold at most one mesocycle session.
    $busy = F::day(date: F::wednesday());
    expect(F::refusal(fn () => Retargeting::move($day, $busy, ClockTime::at(12), F::week(), F::context(), F::newId())))
        ->toBe(RetargetRefusal::DayHasSession);
});

// SCH-050

it('SCH-050 moving the workout time replaces and retargets unchecked meals', function () {
    $day = F::day();
    $result = Retargeting::retime($day, ClockTime::at(12), F::context(), F::newId());
    expect($result)->not->toBeNull()
        ->and($result->days)->toHaveCount(1);
    $moved = $result->days[0];

    expect($moved->session->time->start)->toEqual(ClockTime::at(12))
        ->and($moved->session->time->lengthMinutes)->toBe(30);
    // SCH-042 around the new start (target 90 minutes before) and end.
    $pre = F::mealOfKind(WeekMealKind::PreWorkout, $moved);
    $post = F::mealOfKind(WeekMealKind::PostWorkout, $moved);
    expect($pre->time)->toEqual(ClockTime::at(10, 30))
        ->and($post->time)->toEqual(ClockTime::at(12, 30));
    // Same session length and intensity: same day total, now split around the new time.
    expect($moved->target)->toEqual(F::dayTarget())
        ->and(F::kcal($moved->meals))->toEqualWithDelta(F::dayTarget()->kcal, F::ACC)
        ->and(F::carbs($moved->meals))->toEqualWithDelta(F::dayTarget()->carbsG, F::ACC)
        ->and($post->meal->target->carbsG)->toBeGreaterThan($moved->meals[count($moved->meals) - 1]->meal->target->carbsG)
        ->and(F::ids($moved->meals))->toEqualCanonicalizing(F::ids($day->meals));

    // The same start is no change.
    expect(Retargeting::retime($day, ClockTime::at(17, 30), F::context(), F::newId()))->toBeNull();
});

it('SCH-050 SCH-043 the shake keeps its time relative to the workout', function () {
    // 24 sets: 70 minutes, so the day has an intra-workout shake 10 minutes into the session.
    $session = F::session(sets: 24, start: ClockTime::at(17));
    $shake = F::meal(17, 10, kind: WeekMealKind::IntraWorkoutShake, kcal: 100, name: 'Intra-workout shake');
    $meals = [F::meal(7), F::meal(10), F::meal(15, 30, kind: WeekMealKind::PreWorkout), $shake,
        F::meal(18, 15, kind: WeekMealKind::PostWorkout), F::meal(21)];
    $day = F::day(session: $session, meals: $meals);

    $moved = Retargeting::retime($day, ClockTime::at(13), F::context(), F::newId())->days[0];
    $newShake = F::mealOfKind(WeekMealKind::IntraWorkoutShake, $moved);
    expect($newShake->id())->toBe($shake->id())
        ->and($newShake->time)->toEqual(ClockTime::at(13, 10))
        ->and($newShake->meal->target->carbsG)->toBeGreaterThan(0)
        ->and(F::kcal($moved->meals))->toEqualWithDelta(F::dayTarget()->kcal, F::ACC);
    // The shake sits between the pre- and post-workout meals.
    $kinds = array_map(fn (RetargetMeal $m) => $m->kind, $moved->meals);
    $shakeIndex = array_search(WeekMealKind::IntraWorkoutShake, $kinds, true);
    expect($kinds[$shakeIndex - 1])->toBe(WeekMealKind::PreWorkout)
        ->and($kinds[$shakeIndex + 1])->toBe(WeekMealKind::PostWorkout);
});

// SCH-051

it('SCH-051 moving the workout to another day retargets both days', function () {
    $session = F::session(sets: 16); // 50 minutes, moderate: 50/60 x 90 x 5 = 375 kcal.
    $source = F::day(session: $session);
    $destination = F::restDay(F::wednesday());

    $result = Retargeting::move($source, $destination, ClockTime::at(12), F::week(), F::context(), F::newId());
    expect($result->days)->toHaveCount(2);
    [$left, $arrived] = $result->days;

    // The source day becomes a rest day at the rest-day target.
    expect($left->session)->toBeNull()
        ->and($left->day->type)->toBe(DayType::Rest)
        ->and($left->target->kcal)->toEqualWithDelta(F::dayTarget()->kcal - 375, F::ACC)
        ->and($left->target->carbsG)->toEqualWithDelta(F::dayTarget()->carbsG - 375 / 4, F::ACC)
        ->and($left->target->proteinG)->toBe(F::dayTarget()->proteinG)
        ->and($left->target->fatG)->toBe(F::dayTarget()->fatG)
        ->and(array_filter($left->meals, fn (RetargetMeal $m) => $m->kind !== WeekMealKind::Regular))->toBe([])
        ->and(array_map(fn (RetargetMeal $m) => $m->time, $left->meals))->toEqual(array_map(fn (RetargetMeal $m) => $m->time, $source->meals))
        ->and(F::kcal($left->meals))->toEqualWithDelta($left->target->kcal, F::ACC);

    // The destination gains the session's energy and meals around it.
    expect($arrived->session->session)->toEqual($session->session)
        ->and($arrived->session->time)->toEqual(new WorkoutTime(ClockTime::at(12), 50))
        ->and($arrived->day->type)->toBe(DayType::Moderate)
        ->and($arrived->target->kcal)->toEqualWithDelta(F::dayTarget()->kcal + 375, F::ACC)
        ->and(F::mealOfKind(WeekMealKind::PreWorkout, $arrived))->not->toBeNull()
        ->and(F::mealOfKind(WeekMealKind::PostWorkout, $arrived))->not->toBeNull()
        ->and(F::kcal($arrived->meals))->toEqualWithDelta($arrived->target->kcal, F::ACC);

    // A session never leaves its plan week.
    $nextWeek = F::restDay(PlanFixtures::date(2026, 1, 12));
    expect(F::refusal(fn () => Retargeting::move($source, $nextWeek, ClockTime::at(12), F::week(), F::context(), F::newId())))
        ->toBe(RetargetRefusal::OutsidePlanWeek);
});

it('SCH-051 a session from outside the plan week is refused', function () {
    $lastWeek = F::day(PlanFixtures::date(2026, 1, 12));
    expect(F::refusal(fn () => Retargeting::move($lastWeek, F::restDay(F::wednesday()), ClockTime::at(12), F::week(), F::context(), F::newId())))
        ->toBe(RetargetRefusal::OutsidePlanWeek);
});

it('SCH-051 a date from another zone is read on the calendar of the plan week', function () {
    // A Brisbane plan week (UTC+10). Monday 5 January there starts at 14:00 UTC on Sunday the 4th, which is outside the week in UTC.
    $week = new PlanWeek(PlanFixtures::date(2026, 1, 5, zone: 'Australia/Brisbane'));
    $mondayUtc = PlanFixtures::date(2026, 1, 4, 14, zone: 'UTC');
    $source = F::day(PlanFixtures::date(2026, 1, 7, zone: 'Australia/Brisbane'));

    $result = Retargeting::move($source, F::restDay($mondayUtc), ClockTime::at(12), $week, F::context(), F::newId());

    expect($result->days)->toHaveCount(2)
        ->and($result->days[1]->session->time->start)->toEqual(ClockTime::at(12));
});

it('SCH-051 the source day keeps its other activity', function () {
    $activity = new OtherActivity('Run', 60, DayType::Moderate); // 450 kcal.
    $source = F::day(session: F::session(sets: 24), activity: $activity); // 70 min hard: 630 kcal.
    expect($source->day->type)->toBe(DayType::Hard);
    $destination = F::restDay(F::wednesday());

    $left = Retargeting::move($source, $destination, ClockTime::at(12), F::week(), F::context(), F::newId())->days[0];
    expect($left->otherActivity)->toEqual($activity)
        ->and($left->day->type)->toBe(DayType::Moderate)
        ->and($left->target->kcal)->toEqualWithDelta(F::dayTarget()->kcal - 630, F::ACC);
});

// SCH-052

it('SCH-052 starting late moves the workout and retargets meals still to come', function () {
    $breakfast = F::meal(7, checkedIn: true);
    $lunch = F::meal(13, checkedIn: true);
    $day = F::day(meals: [$breakfast, F::meal(10), $lunch, F::meal(16, kind: WeekMealKind::PreWorkout),
        F::meal(19, kind: WeekMealKind::PostWorkout)]);
    $started = Retargeting::retime($day, ClockTime::at(18, 15), F::context(), F::newId())->days[0];

    expect($started->session->time->start)->toEqual(ClockTime::at(18, 15))
        ->and(F::mealWithId($breakfast->id(), $started))->toEqual($breakfast)
        ->and(F::mealWithId($lunch->id(), $started))->toEqual($lunch);
    $post = F::mealOfKind(WeekMealKind::PostWorkout, $started);
    expect($post->time)->toEqual(ClockTime::at(18, 45))
        ->and(F::kcal($started->meals))->toEqualWithDelta(F::dayTarget()->kcal, F::ACC);
});

// SCH-053

it('SCH-053 skipping turns the day into a rest day and unchecked meals share what remains', function () {
    $session = F::session(sets: 16); // 375 kcal.
    $breakfast = F::meal(7, kcal: 700, checkedIn: true);
    $day = F::day(session: $session, meals: [$breakfast, F::meal(10), F::meal(13),
        F::meal(16, kind: WeekMealKind::PreWorkout), F::meal(19, kind: WeekMealKind::PostWorkout)]);
    $result = Retargeting::skip($day, F::context());

    // Workout energy is removed, not moved to other days.
    expect($result->days)->toHaveCount(1);
    $rest = $result->days[0];
    expect($rest->session)->toBeNull()
        ->and($rest->day->type)->toBe(DayType::Rest)
        ->and($rest->target->kcal)->toEqualWithDelta(F::dayTarget()->kcal - 375, F::ACC)
        ->and($rest->meals[0])->toEqual($breakfast);
    $unchecked = array_slice($rest->meals, 1);
    expect(F::kcal($unchecked))->toEqualWithDelta($rest->target->kcal - 700, F::ACC)
        ->and(F::carbs($unchecked))->toEqualWithDelta($rest->target->carbsG - 60, F::ACC);
    // Rest-day split: carbs even, up to the NUT-034 rounding (a 5 g step and the remainder).
    $carbs = array_map(fn (RetargetMeal $m) => $m->meal->target->carbsG, $unchecked);
    expect(max($carbs) - min($carbs))->toBeLessThan(10)
        ->and(array_filter($unchecked, fn (RetargetMeal $m) => $m->kind !== WeekMealKind::Regular))->toBe([]);
});

it('SCH-053 SAFE-010 floors win over the day total', function () {
    $feast = F::meal(7, target: new MealTarget(kcal: 2200, proteinG: 120, fatG: 70, carbsG: 300), checkedIn: true);
    $day = F::day(session: F::session(sets: 16), meals: [$feast, F::meal(10), F::meal(13),
        F::meal(16, kind: WeekMealKind::PreWorkout), F::meal(19, kind: WeekMealKind::PostWorkout)]);
    $rest = Retargeting::skip($day, F::context())->days[0];
    foreach (array_slice($rest->meals, 1) as $meal) {
        expect($meal->meal->target->carbsG)->toBeGreaterThanOrEqual(5)
            ->and($meal->meal->target->fatG)->toBeGreaterThanOrEqual(5);
    }
});

it('SCH-053 the shake goes with the skipped workout', function () {
    $session = F::session(sets: 24, start: ClockTime::at(17));
    $meals = [F::meal(7), F::meal(11), F::meal(15, 30, kind: WeekMealKind::PreWorkout),
        F::meal(17, kind: WeekMealKind::IntraWorkoutShake, kcal: 100), F::meal(18, 15, kind: WeekMealKind::PostWorkout)];
    $rest = Retargeting::skip(F::day(session: $session, meals: $meals), F::context())->days[0];
    expect(F::mealOfKind(WeekMealKind::IntraWorkoutShake, $rest))->toBeNull()
        ->and($rest->meals)->toHaveCount(4)
        ->and(F::kcal($rest->meals))->toEqualWithDelta($rest->target->kcal, F::ACC);
});

// SCH-054

it('SCH-054 a long session retargets from the actual length', function () {
    $day = F::day(session: F::session(sets: 16)); // 50 min moderate: 375 kcal.
    expect(Retargeting::ranFor($day, 70, F::context(), F::newId()))->toBeNull()
        ->and(Retargeting::ranFor($day, 30, F::context(), F::newId()))->toBeNull();

    // 80 minutes is over 75, so the session is Hard: 80/60 x 90 x 6 = 720 kcal, 345 more.
    $long = Retargeting::ranFor($day, 80, F::context(), F::newId())->days[0];
    expect($long->session->time->lengthMinutes)->toBe(80)
        ->and($long->session->intensity)->toBe(DayType::Hard)
        ->and($long->day->type)->toBe(DayType::Hard)
        ->and($long->target->kcal)->toEqualWithDelta(F::dayTarget()->kcal + 345, F::ACC)
        ->and($long->target->carbsG)->toEqualWithDelta(F::dayTarget()->carbsG + 345 / 4, F::ACC)
        ->and(F::kcal($long->meals))->toEqualWithDelta($long->target->kcal, F::ACC)
        // Re-placed around the new end (17:30 + 80 min = 18:50).
        ->and(F::mealOfKind(WeekMealKind::PostWorkout, $long)?->time)->toEqual(ClockTime::at(18, 50))
        // Over 60 minutes now: the day gains an intra-workout shake at the start.
        ->and(F::mealOfKind(WeekMealKind::IntraWorkoutShake, $long)?->time)->toEqual(ClockTime::at(17, 30));
    // The new shake takes the first id the caller hands out, and its name.
    $shake = F::mealOfKind(WeekMealKind::IntraWorkoutShake, $long);
    expect($shake->id())->toBe('n1')
        ->and($shake->meal->name)->toBe(MealNames::INTRA_WORKOUT_SHAKE);
});

it('SCH-054 a short session retargets and keeps its sets intensity', function () {
    $session = F::session(sets: 24, start: ClockTime::at(17)); // 70 min hard (20+ sets): 630 kcal.
    $meals = [F::meal(7), F::meal(11), F::meal(15, 30, kind: WeekMealKind::PreWorkout),
        F::meal(17, kind: WeekMealKind::IntraWorkoutShake, kcal: 100), F::meal(18, 15, kind: WeekMealKind::PostWorkout)];
    $day = F::day(session: $session, meals: $meals);
    $short = Retargeting::ranFor($day, 40, F::context(), F::newId())->days[0];
    expect($short->session->intensity)->toBe(DayType::Hard)
        ->and($short->target->kcal)->toEqualWithDelta(F::dayTarget()->kcal - 630 + 360, F::ACC)
        // 40 minutes no longer earns a shake.
        ->and(F::mealOfKind(WeekMealKind::IntraWorkoutShake, $short))->toBeNull()
        ->and(F::kcal($short->meals))->toEqualWithDelta($short->target->kcal, F::ACC);
});

it('SCH-054 a deload week session stays light', function () {
    $day = F::day(session: F::session(sets: 8));
    $context = new RetargetContext(weightKg: 90, eatingWindow: F::window(), isDeloadWeek: true);
    $long = Retargeting::ranFor($day, 90, $context, F::newId())->days[0];
    expect($long->session->intensity)->toBe(DayType::Light);
});

// SCH-055

it('SCH-055 checked, locked and hand-edited meals never change', function () {
    $checked = F::meal(7, checkedIn: true);
    $locked = F::meal(9, 30, kcal: 450, locked: true);
    $handTarget = F::meal(12, kcal: 650, handEditedTarget: true);
    $handTime = F::meal(15, handSetTime: true);
    $day = F::day(meals: [$checked, $locked, $handTarget, $handTime, F::meal(19, kind: WeekMealKind::PostWorkout)]);

    $changes = [
        Retargeting::retime($day, ClockTime::at(18), F::context(), F::newId())->days[0],
        Retargeting::ranFor($day, 90, F::context(), F::newId())->days[0],
        Retargeting::skip($day, F::context())->days[0],
        Retargeting::move($day, F::restDay(F::wednesday()), ClockTime::at(12), F::week(), F::context(), F::newId())->days[0],
    ];
    foreach ($changes as $changed) {
        foreach ([$checked, $locked, $handTarget] as $fixed) {
            expect(F::mealWithId($fixed->id(), $changed))->toEqual($fixed);
        }
        $movedHandTime = F::mealWithId($handTime->id(), $changed);
        expect($movedHandTime->time)->toEqual($handTime->time)
            ->and($movedHandTime->meal->target)->not->toEqual($handTime->meal->target)
            ->and(F::kcal($changed->meals))->toEqualWithDelta($changed->target->kcal, F::ACC);
    }
});

it('SCH-055 a checked-in pre-workout meal keeps its kind', function () {
    $checked = F::meal(16, kind: WeekMealKind::PreWorkout, checkedIn: true);
    $day = F::day(meals: [F::meal(7), F::meal(10), F::meal(13), $checked, F::meal(19, kind: WeekMealKind::PostWorkout)]);
    $moved = Retargeting::retime($day, ClockTime::at(12), F::context(), F::newId())->days[0];
    expect(F::mealWithId($checked->id(), $moved))->toEqual($checked)
        ->and(F::kcal($moved->meals))->toEqualWithDelta($moved->target->kcal, F::ACC);
});

it('SCH-055 SCH-043 a checked-in shake never changes', function () {
    $session = F::session(sets: 24, start: ClockTime::at(17));
    $shake = F::meal(
        17, 10, kind: WeekMealKind::IntraWorkoutShake,
        target: new MealTarget(kcal: 200, proteinG: 20, fatG: 0, carbsG: 30), checkedIn: true,
    );
    $meals = [F::meal(7), F::meal(10), F::meal(15, 30, kind: WeekMealKind::PreWorkout), $shake,
        F::meal(18, 15, kind: WeekMealKind::PostWorkout), F::meal(21)];
    $day = F::day(session: $session, meals: $meals);

    $changes = [
        Retargeting::retime($day, ClockTime::at(13), F::context(), F::newId())->days[0],
        Retargeting::skip($day, F::context())->days[0],
    ];
    foreach ($changes as $changed) {
        expect(array_values(array_filter($changed->meals, fn (RetargetMeal $m) => $m->kind === WeekMealKind::IntraWorkoutShake)))
            ->toEqual([$shake]);
        // Still in time order: the shake stays at 17:10 after the workout moves to 13:00.
        $times = F::minutes($changed->meals);
        $sorted = $times;
        sort($sorted);
        expect($times)->toBe($sorted)
            ->and(F::kcal($changed->meals))->toEqualWithDelta($changed->target->kcal, F::ACC)
            ->and(F::carbs($changed->meals))->toEqualWithDelta($changed->target->carbsG, F::ACC);
    }
});

it('SCH-055 SCH-043 a checked-in shake before the session leaves the peri-workout meals around the workout', function () {
    $session = F::session(sets: 24, start: ClockTime::at(17)); // 70 minutes.
    $shake = F::meal(
        17, 10, kind: WeekMealKind::IntraWorkoutShake,
        target: new MealTarget(kcal: 200, proteinG: 20, fatG: 0, carbsG: 30), checkedIn: true,
    );
    $meals = [F::meal(7), F::meal(10), F::meal(15, 30, kind: WeekMealKind::PreWorkout), $shake,
        F::meal(18, 15, kind: WeekMealKind::PostWorkout), F::meal(21)];

    // The workout moves to 19:00, after the shake's 17:10: the shake is no longer inside the session.
    $moved = Retargeting::retime(F::day(session: $session, meals: $meals), ClockTime::at(19), F::context(), F::newId())->days[0];
    $pre = F::mealOfKind(WeekMealKind::PreWorkout, $moved);
    $post = F::mealOfKind(WeekMealKind::PostWorkout, $moved);
    $regular = array_filter($moved->meals, fn (RetargetMeal $m) => $m->kind === WeekMealKind::Regular);

    expect(F::mealWithId($shake->id(), $moved)->time)->toEqual($shake->time)
        // The peri-workout meals get the workout carbs, not the shake or a regular meal.
        ->and($pre->meal->target->carbsG)->toBeGreaterThan(max(array_map(fn (RetargetMeal $m) => $m->meal->target->carbsG, $regular)))
        ->and($post->meal->target->carbsG)->toBeGreaterThan($pre->meal->target->carbsG)
        ->and(F::kcal($moved->meals))->toEqualWithDelta($moved->target->kcal, F::ACC);
});

it('SCH-055 SCH-044 a hand-timed shake keeps its time', function () {
    $session = F::session(sets: 24, start: ClockTime::at(17));
    $shake = F::meal(17, 20, kind: WeekMealKind::IntraWorkoutShake, kcal: 100, handSetTime: true);
    $meals = [F::meal(7), F::meal(10), F::meal(15, 30, kind: WeekMealKind::PreWorkout), $shake,
        F::meal(18, 15, kind: WeekMealKind::PostWorkout), F::meal(21)];
    $moved = Retargeting::retime(F::day(session: $session, meals: $meals), ClockTime::at(13), F::context(), F::newId())->days[0];
    $newShake = F::mealOfKind(WeekMealKind::IntraWorkoutShake, $moved);
    expect($newShake->time)->toEqual($shake->time)
        ->and($newShake->meal->target)->not->toEqual($shake->meal->target)
        ->and(F::kcal($moved->meals))->toEqualWithDelta($moved->target->kcal, F::ACC);
});

// SCH-060 with SCH-053, SCH-054

it('SCH-053 SCH-054 an away week on rest-day calories never counted the workout', function () {
    // SCH-060: an away week on rest-day calories leaves workout energy out of every day's target.
    $context = new RetargetContext(weightKg: 90, eatingWindow: F::window(), countsWorkoutEnergy: false);
    $day = F::day(session: F::session(sets: 16));
    $skipped = Retargeting::skip($day, $context)->days[0];
    expect($skipped->target)->toEqual(F::dayTarget())
        ->and($skipped->day->type)->toBe(DayType::Rest);
    $long = Retargeting::ranFor($day, 80, $context, F::newId())->days[0];
    expect($long->target)->toEqual(F::dayTarget());
});

// SCH-056

it('SCH-056 every retarget returns a toast and an undo snapshot', function () {
    $day = F::day();
    $retimed = Retargeting::retime($day, ClockTime::at(17, 45), F::context(), F::newId());
    expect($retimed->message)->toBe('Meals updated for your 5:45 PM workout')
        ->and($retimed->undo)->toEqual([$day]);

    $early = Retargeting::retime($day, ClockTime::at(12, 5), F::context(), F::newId());
    expect($early->message)->toBe('Meals updated for your 12:05 PM workout');

    $long = Retargeting::ranFor($day, 60, F::context(), F::newId());
    expect($long->message)->toBe('Meals updated for your 5:30 PM workout')
        ->and($long->undo)->toEqual([$day]);

    $skipped = Retargeting::skip($day, F::context());
    expect($skipped->message)->toBe('Meals updated for the skipped workout')
        ->and($skipped->undo)->toEqual([$day]);

    $destination = F::restDay(F::wednesday());
    $moved = Retargeting::move($day, $destination, ClockTime::at(6, 30), F::week(), F::context(), F::newId());
    expect($moved->message)->toBe('Meals updated for your 6:30 AM workout')
        ->and($moved->undo)->toEqual([$day, $destination]);
});

// SCH-052 started at a different time

it('SCH-052 a start more than 20 minutes from planned retimes the day', function () {
    $day = F::day();
    $result = Retargeting::started($day, ClockTime::at(18, 15), F::context(), F::newId());
    expect($result->days[0]->session->time->start)->toEqual(ClockTime::at(18, 15))
        ->and($result->undo)->toEqual([$day])
        ->and($result->message)->toBe('Meals updated for your 6:15 PM workout')
        // An early start counts the same way.
        ->and(Retargeting::started($day, ClockTime::at(16, 59), F::context(), F::newId()))->not->toBeNull();
});

it('SCH-052 a start within 20 minutes of planned changes nothing', function () {
    $day = F::day();
    expect(Retargeting::started($day, ClockTime::at(17, 50), F::context(), F::newId()))->toBeNull()
        ->and(Retargeting::started($day, ClockTime::at(17, 10), F::context(), F::newId()))->toBeNull()
        ->and(F::refusal(fn () => Retargeting::started(F::restDay(), ClockTime::at(9), F::context(), F::newId())))
        ->toBe(RetargetRefusal::NoSession);
});

// SCH-057

it('SCH-057 missed day options', function () {
    $week = F::week(); // Monday 5 to Sunday 11 January.
    $day = fn (int $n) => PlanFixtures::date(2026, 1, $n);
    $options = fn (int $missed, int $today, array $training) => Retargeting::missedDayOptions(
        missed: $day($missed), today: $day($today), week: $week, trainingDates: array_map($day, $training));

    // Today is a rest day: do it today, or skip.
    expect($options(5, 6, [5, 7, 9]))->toEqual([MissedDayOption::doItToday(), MissedDayOption::skip()])
        // Today is a training day and Thursday is still a rest day.
        ->and($options(5, 6, [5, 6, 8]))->toEqual([MissedDayOption::nextRestDay($day(7)), MissedDayOption::skip()])
        // No rest day left before the week ends.
        ->and($options(9, 10, [9, 10, 11]))->toEqual([MissedDayOption::skip()])
        // Missed on the week's last day: today is in the next plan week.
        ->and($options(11, 12, [11]))->toEqual([MissedDayOption::skip()])
        // Missed the day before the week, with today its first day.
        ->and($options(4, 5, [4]))->toEqual([MissedDayOption::skip()]);
});

it('SCH-057 dates from another zone are read on the calendar of the plan week', function () {
    // A Brisbane plan week (UTC+10): each of its days begins at 14:00 UTC the day before.
    $week = new PlanWeek(PlanFixtures::date(2026, 1, 5, zone: 'Australia/Brisbane'));
    $utc = fn (int $day) => PlanFixtures::date(2026, 1, $day, 14, zone: 'UTC'); // 14:00 UTC on $day is midnight on $day + 1 in Brisbane.

    // Missed Monday 5, today Wednesday 7 (a training day), trained Monday and Wednesday: Thursday is the next rest day.
    $options = Retargeting::missedDayOptions(missed: $utc(4), today: $utc(6), week: $week, trainingDates: [$utc(4), $utc(6)]);

    expect($options)->toEqual([
        MissedDayOption::nextRestDay(PlanFixtures::date(2026, 1, 8, zone: 'Australia/Brisbane')),
        MissedDayOption::skip(),
    ]);
});

// SCH-031 add meal

it('SCH-031 add meal puts a new meal at the time and leaves every other time alone', function () {
    $day = F::day();
    $result = Retargeting::addMeal($day, ClockTime::at(15), F::context(), 'extra-1');
    $added = $result->days[0];

    expect($result->days)->toHaveCount(1)
        ->and($added->meals)->toHaveCount(6)
        ->and(F::minutes($added->meals))->toBe(array_map(fn (int $h) => $h * 60, [7, 10, 13, 15, 16, 19]));
    $new = F::mealWithId('extra-1', $added);
    expect(F::ids($day->meals))->not->toContain($new->id())
        ->and($new->meal->name)->toBe(MealNames::EXTRA_MEAL)
        ->and($new->time)->toEqual(ClockTime::at(15))
        ->and($new->kind)->toBe(WeekMealKind::Regular)
        ->and($new->meal->target->kcal)->toBeGreaterThan(0);
    foreach ($day->meals as $old) {
        expect(F::mealWithId($old->id(), $added)->time)->toEqual($old->time);
    }
});

it('SCH-031 add meal keeps the days total, type and session and shares the total across the meals', function () {
    foreach ([F::day(), F::restDay()] as $day) {
        $added = Retargeting::addMeal($day, ClockTime::at(21), F::context(), 'extra-1')->days[0];
        expect($added->target)->toEqual($day->target)
            ->and($added->day)->toEqual($day->day)
            ->and($added->session)->toEqual($day->session)
            ->and(F::kcal($added->meals))->toEqualWithDelta($day->target->kcal, F::ACC)
            ->and(F::carbs($added->meals))->toEqualWithDelta($day->target->carbsG, F::ACC)
            ->and(F::protein($added->meals))->toEqualWithDelta($day->target->proteinG, F::ACC);
    }
});

it('SCH-031 add meal never changes a checked-in meal and splits what is left', function () {
    $checked = F::meal(7, checkedIn: true);
    $day = F::day(meals: [
        $checked, F::meal(10), F::meal(13), F::meal(16, kind: WeekMealKind::PreWorkout),
        F::meal(19, kind: WeekMealKind::PostWorkout),
    ]);
    $added = Retargeting::addMeal($day, ClockTime::at(11), F::context(), 'extra-1')->days[0];

    expect(F::mealWithId($checked->id(), $added))->toEqual($checked)
        ->and(F::kcal($added->meals))->toEqualWithDelta($day->target->kcal, F::ACC)
        ->and(F::mealWithId($day->meals[1]->id(), $added)->meal->target)->not->toEqual($day->meals[1]->meal->target);
});

it('SCH-031 add meal on a training day gives the peri-workout meals the workout carbs', function () {
    $day = F::day();
    $added = Retargeting::addMeal($day, ClockTime::at(9), F::context(), 'extra-1')->days[0];
    $pre = F::mealOfKind(WeekMealKind::PreWorkout, $added);
    $post = F::mealOfKind(WeekMealKind::PostWorkout, $added);
    $regular = array_values(array_filter($added->meals, fn (RetargetMeal $m) => $m->kind === WeekMealKind::Regular));
    expect($regular)->toHaveCount(4);
    foreach ($regular as $meal) {
        expect($pre->meal->target->carbsG)->toBeGreaterThan($meal->meal->target->carbsG)
            ->and($post->meal->target->carbsG)->toBeGreaterThan($meal->meal->target->carbsG);
    }
});

it('SCH-031 add meal on a rest day has no peri-workout meals', function () {
    $added = Retargeting::addMeal(F::restDay(), ClockTime::at(15), F::context(), 'extra-1')->days[0];
    expect(array_filter($added->meals, fn (RetargetMeal $m) => $m->kind !== WeekMealKind::Regular))->toBe([])
        ->and($added->session)->toBeNull();
});

it('SCH-031 SCH-043 add meal on a day with a shake keeps the shake at the workout', function () {
    $shake = F::meal(18, kind: WeekMealKind::IntraWorkoutShake, kcal: 200);
    $day = F::day(meals: [
        F::meal(7), F::meal(10), F::meal(13), F::meal(16, kind: WeekMealKind::PreWorkout), $shake,
        F::meal(19, kind: WeekMealKind::PostWorkout),
    ]);
    $added = Retargeting::addMeal($day, ClockTime::at(9), F::context(), 'extra-1')->days[0];

    expect(F::minutes($added->meals))->toBe([7 * 60, 9 * 60, 10 * 60, 13 * 60, 16 * 60, 18 * 60, 19 * 60]);
    $kept = F::mealWithId($shake->id(), $added);
    expect($kept->time)->toEqual($shake->time)
        ->and($kept->kind)->toBe(WeekMealKind::IntraWorkoutShake)
        ->and(F::kcal($added->meals))->toEqualWithDelta($day->target->kcal, F::ACC)
        ->and(F::carbs($added->meals))->toEqualWithDelta($day->target->carbsG, F::ACC);
    $pre = F::mealOfKind(WeekMealKind::PreWorkout, $added);
    $post = F::mealOfKind(WeekMealKind::PostWorkout, $added);
    foreach ($added->meals as $meal) {
        if ($meal->kind === WeekMealKind::Regular) {
            expect($pre->meal->target->carbsG)->toBeGreaterThan($meal->meal->target->carbsG)
                ->and($post->meal->target->carbsG)->toBeGreaterThan($meal->meal->target->carbsG);
        }
    }
});

/** A time outside the eating window goes on the side of the window it is nearer, as the workout does. */
it('SCH-031 add meal outside the window goes on the nearer side', function () {
    $context = new RetargetContext(weightKg: 90, eatingWindow: new ClockRange(ClockTime::at(7), ClockTime::at(22)));
    $day = F::day();
    $times = fn (RetargetDay $d) => array_map(fn (RetargetMeal $m) => $m->time, $d->meals);

    $early = Retargeting::addMeal($day, ClockTime::at(6, 30), $context, 'extra-1')->days[0];
    expect($times($early))->toEqual([ClockTime::at(6, 30), ...$times($day)]);
    // The early meal is the day's first, before the workout: the post-workout meal keeps the most carbs after it.
    $post = F::mealOfKind(WeekMealKind::PostWorkout, $early);
    expect($post->meal->target->carbsG)->toBeGreaterThan($early->meals[0]->meal->target->carbsG);

    $late = Retargeting::addMeal($day, ClockTime::at(23), $context, 'extra-1')->days[0];
    expect($times($late))->toEqual([...$times($day), ClockTime::at(23)]);
});

/** SCH-045: a window from 10:00 to 02:00. 01:00 is late in the day; 09:30 is just before it starts. */
it('SCH-031 SCH-045 add meal in a window that crosses midnight', function () {
    $context = new RetargetContext(weightKg: 90, eatingWindow: new ClockRange(ClockTime::at(10), ClockTime::at(2)));
    $day = F::day(meals: [
        F::meal(11), F::meal(14), F::meal(16, kind: WeekMealKind::PreWorkout),
        F::meal(19, kind: WeekMealKind::PostWorkout), F::meal(23),
    ]);
    $times = fn (RetargetDay $d) => array_map(fn (RetargetMeal $m) => $m->time, $d->meals);

    $night = Retargeting::addMeal($day, ClockTime::at(1), $context, 'extra-1')->days[0];
    expect($times($night))->toEqual([...$times($day), ClockTime::at(1)]);
    $morning = Retargeting::addMeal($day, ClockTime::at(9, 30), $context, 'extra-1')->days[0];
    expect($times($morning))->toEqual([ClockTime::at(9, 30), ...$times($day)]);
    foreach ([$night, $morning] as $added) {
        expect(F::kcal($added->meals))->toEqualWithDelta($day->target->kcal, F::ACC);
    }
});

it('SCH-031 SCH-056 add meal returns a toast and an undo snapshot', function () {
    $day = F::day();
    $result = Retargeting::addMeal($day, ClockTime::at(15), F::context(), 'extra-1');
    expect($result->message)->toBe('Meal added at 3:00 PM')
        ->and($result->undo)->toEqual([$day])
        ->and(Retargeting::addMeal($day, ClockTime::at(8, 5), F::context(), 'extra-1')->message)->toBe('Meal added at 8:05 AM');
});

// SCH-036 optimize

it('SCH-036 optimize replaces unchecked meals and only those', function () {
    $checked = F::meal(7, checkedIn: true);
    $day = F::day(meals: [
        $checked, F::meal(8), F::meal(9), F::meal(10, kind: WeekMealKind::Regular), F::meal(11),
    ]);
    $result = Retargeting::optimize($day, F::context(), F::newId());
    expect($result)->not->toBeNull();
    $optimized = $result->days[0];

    expect(F::mealWithId($checked->id(), $optimized))->toEqual($checked)
        ->and(F::minutes($optimized->meals))->not->toBe(F::minutes($day->meals))
        ->and(F::ids($optimized->meals))->toEqualCanonicalizing(F::ids($day->meals))
        ->and($optimized->target)->toEqual($day->target)
        ->and(F::kcal($optimized->meals))->toEqualWithDelta($day->target->kcal, F::ACC)
        ->and($result->undo)->toEqual([$day])
        ->and($result->message)->toBe('Meals optimized');
});

it('SCH-036 optimize returns nil when no meal would move', function () {
    $day = F::day();
    $once = Retargeting::optimize($day, F::context(), F::newId())->days[0];
    expect(Retargeting::optimize($once, F::context(), F::newId()))->toBeNull();
});

/** The meals already sit where Optimize puts them; the only change is a shake the day no longer earns. */
it('SCH-036 SCH-043 optimize is not nil when the only change is a dropped shake', function () {
    $rest = F::restDay();
    $placed = Retargeting::optimize($rest, F::context(), F::newId())->days[0];
    $shake = F::meal(18, kind: WeekMealKind::IntraWorkoutShake, kcal: 200);
    $day = $placed->withMeals([...$placed->meals, $shake]);
    expect(Retargeting::optimize($placed, F::context(), F::newId()))->toBeNull('the meals alone are already optimal');

    $result = Retargeting::optimize($day, F::context(), F::newId());

    expect($result)->not->toBeNull();
    $optimized = $result->days[0];
    expect(F::mealOfKind(WeekMealKind::IntraWorkoutShake, $optimized))->toBeNull()
        ->and($optimized->meals)->toHaveCount(count($placed->meals))
        ->and(array_map(fn (RetargetMeal $m) => $m->time, $optimized->meals))
        ->toEqual(array_map(fn (RetargetMeal $m) => $m->time, $placed->meals))
        ->and($result->undo)->toEqual([$day]);
});

it('SCH-036 optimize refuses when the meals do not fit', function () {
    $checked = F::meal(12, checkedIn: true);
    $day = F::restDay(meals: [$checked, F::meal(12, 30), F::meal(13)]);
    $narrow = new RetargetContext(weightKg: 90, eatingWindow: new ClockRange(ClockTime::at(12), ClockTime::at(13)));
    expect(F::refusal(fn () => Retargeting::optimize($day, $narrow, F::newId())))->toBe(RetargetRefusal::MealsDoNotFit);
});

// SCH-032, SCH-038, NUT-003, NUT-005 Other activity

it('NUT-003 NUT-005 an activity adds its energy at the picked intensity', function () {
    // 90 kg, 60 minutes moderate: 1 x 90 x 5 = 450 kcal, all of it carbs.
    $day = F::restDay();
    $activity = new OtherActivity('Run', 60, DayType::Moderate);

    $result = Retargeting::otherActivity($day, $activity, F::context());
    $after = $result->days[0];

    expect($result->days)->toHaveCount(1)
        ->and($after->otherActivity)->toEqual($activity)
        ->and($after->target->kcal)->toEqualWithDelta(F::dayTarget()->kcal + 450, F::ACC)
        ->and($after->target->carbsG)->toEqualWithDelta(F::dayTarget()->carbsG + 112.5, F::ACC)
        ->and($after->target->proteinG)->toBe(F::dayTarget()->proteinG)
        ->and($after->target->fatG)->toBe(F::dayTarget()->fatG)
        ->and(F::kcal($after->meals))->toEqualWithDelta($after->target->kcal, F::ACC)
        ->and(F::carbs($after->meals))->toEqualWithDelta($after->target->carbsG, F::ACC)
        ->and($result->undo)->toEqual([$day])
        ->and($result->message)->toBe('Meals updated for Run');
});

it('NUT-005 a hard activity uses the hard factor', function () {
    $day = F::restDay();
    $activity = new OtherActivity('HIIT', 30, DayType::Hard); // 0.5 x 90 x 6 = 270.
    $after = Retargeting::otherActivity($day, $activity, F::context())->days[0];
    expect($after->target->kcal)->toEqualWithDelta(F::dayTarget()->kcal + 270, F::ACC)
        ->and($after->day->type)->toBe(DayType::Hard);
});

it('SCH-038 a rest day with a moderate activity becomes moderate', function () {
    $day = F::restDay();
    expect($day->day->type)->toBe(DayType::Rest);
    $activity = new OtherActivity('Run', 45, DayType::Moderate);
    $after = Retargeting::otherActivity($day, $activity, F::context())->days[0];
    expect($after->day->type)->toBe(DayType::Moderate);
});

it('SCH-038 a hard session with a moderate activity stays hard', function () {
    // 24 sets: 70 minutes, hard.
    $day = F::day(session: F::session(sets: 24));
    expect($day->day->type)->toBe(DayType::Hard);
    $activity = new OtherActivity('Run', 45, DayType::Moderate);
    $after = Retargeting::otherActivity($day, $activity, F::context())->days[0];
    expect($after->day->type)->toBe(DayType::Hard)
        // The activity's energy joins the session's: 0.75 x 90 x 5 = 337.5.
        ->and($after->target->kcal)->toEqualWithDelta(F::dayTarget()->kcal + 337.5, F::ACC);
});

it('SCH-038 the meal times and kinds stay around the session only', function () {
    $day = F::day();
    $activity = new OtherActivity('Run', 60, DayType::Moderate);
    $after = Retargeting::otherActivity($day, $activity, F::context())->days[0];
    expect(array_map(fn (RetargetMeal $m) => $m->time, $after->meals))->toEqual(array_map(fn (RetargetMeal $m) => $m->time, $day->meals))
        ->and(array_map(fn (RetargetMeal $m) => $m->kind, $after->meals))->toBe(array_map(fn (RetargetMeal $m) => $m->kind, $day->meals))
        ->and(F::ids($after->meals))->toBe(F::ids($day->meals))
        ->and($after->session)->toEqual($day->session)
        ->and(F::kcal($after->meals))->toEqualWithDelta($after->target->kcal, F::ACC);
});

it('SCH-055 NUT-003 a checked-in meal is never changed by an activity', function () {
    $lunch = F::meal(13, checkedIn: true);
    $day = F::restDay(meals: [F::meal(7), F::meal(10), $lunch, F::meal(16), F::meal(19)]);
    $activity = new OtherActivity('Run', 60, DayType::Moderate);
    $after = Retargeting::otherActivity($day, $activity, F::context())->days[0];
    expect(F::mealWithId($lunch->id(), $after))->toEqual($lunch)
        ->and(F::kcal($after->meals))->toEqualWithDelta($after->target->kcal, F::ACC);
});

it('SCH-032 removing the activity takes its energy back out', function () {
    $activity = new OtherActivity('Run', 60, DayType::Moderate);
    $with = F::restDay(activity: $activity);
    // The stored total already holds the activity's 450 kcal.
    $stored = $with->withTarget(new MealTarget(
        kcal: $with->target->kcal + 450, proteinG: $with->target->proteinG, fatG: $with->target->fatG,
        carbsG: $with->target->carbsG + 112.5,
    ));

    $result = Retargeting::otherActivity($stored, null, F::context());
    $after = $result->days[0];
    expect($after->otherActivity)->toBeNull()
        ->and($after->target->kcal)->toEqualWithDelta(F::dayTarget()->kcal, F::ACC)
        ->and($after->target->carbsG)->toEqualWithDelta(F::dayTarget()->carbsG, F::ACC)
        ->and($after->day->type)->toBe(DayType::Rest)
        ->and($result->message)->toBe('Meals updated: Run removed')
        ->and($result->undo)->toEqual([$stored]);
});

it('SCH-032 editing the activity changes only the difference', function () {
    $old = new OtherActivity('Run', 60, DayType::Moderate);
    $base = F::restDay(activity: $old);
    $stored = $base->withTarget(new MealTarget(
        kcal: $base->target->kcal + 450, proteinG: $base->target->proteinG, fatG: $base->target->fatG,
        carbsG: $base->target->carbsG + 112.5,
    ));
    $new = new OtherActivity('Run', 90, DayType::Moderate); // 675 kcal.
    $after = Retargeting::otherActivity($stored, $new, F::context())->days[0];
    expect($after->target->kcal)->toEqualWithDelta(F::dayTarget()->kcal + 675, F::ACC);
});

it('SCH-060 an away week on rest-day calories leaves the activity energy out', function () {
    $context = new RetargetContext(weightKg: 90, eatingWindow: F::window(), countsWorkoutEnergy: false);
    $day = F::restDay();
    $activity = new OtherActivity('Run', 60, DayType::Moderate);
    $after = Retargeting::otherActivity($day, $activity, $context)->days[0];
    expect($after->target->kcal)->toEqualWithDelta(F::dayTarget()->kcal, F::ACC);
});
