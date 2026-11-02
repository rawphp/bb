<?php

use App\Domain\Macros\MoveRefusalReason;
use App\Domain\Macros\TargetRefusalReason;
use App\Domain\Model\DayType;
use App\Domain\Model\ExerciseSlot;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\PhaseType;
use App\Domain\Placement\ClockRange;
use App\Domain\Placement\ClockTime;
use App\Domain\Placement\TrainingDay;
use App\Domain\Placement\WorkoutTime;
use App\Domain\Plan\PlanWeekGrid;
use App\Domain\Retarget\OtherActivity;
use App\Domain\Retarget\TrainingWeekdays;
use App\Domain\WeekBuilder\AwayWeek;
use App\Domain\WeekBuilder\AwayWeek\Calories;
use App\Domain\WeekBuilder\AwayWeek\Training;
use App\Domain\WeekBuilder\CalorieMove;
use App\Domain\WeekBuilder\NutritionSchedule;
use App\Domain\WeekBuilder\WeekBuildRefusalReason;
use App\Domain\WeekBuilder\WeekDay;
use App\Domain\WeekBuilder\WeekMeal;
use App\Domain\WeekBuilder\WeekMealKind;
use App\Domain\WeekBuilder\WeekSchedule;
use App\Domain\WeekBuilder\WeekSessions\SessionRow;
use App\Domain\WeekBuilder\WeekWorkout;
use Tests\Unit\Domain\Plan\PlanFixtures;
use Tests\Unit\Domain\WeekBuilder\WeekBuilderFixtures as F;

// The same tests as rp-clone's WeekBuilderTests (pin 2aa1edeb), one for one.

// A full week

it('SCH-040 a full week has seven days with workouts on the training days', function () {
    $built = F::build();

    expect($built->days)->toHaveCount(7)
        ->and(array_map(fn (WeekDay $day) => $day->day->date, $built->days))
        ->toEqual(array_map(fn (int $offset) => PlanFixtures::date(2026, 1, 5 + $offset), range(0, 6)))
        ->and(F::weekdays($built))->toBe([2, 3, 4, 5, 6, 7, 1])
        ->and($built->phase->type)->toBe(PhaseType::FatLoss);

    foreach ($built->days as $day) {
        $weekday = (int) $day->day->date->format('w') + 1;
        if (in_array($weekday, [F::MONDAY, F::WEDNESDAY, F::FRIDAY], true)) {
            expect($day->workouts)->toHaveCount(1, "weekday {$weekday}")
                ->and($day->workouts[0]->time)->toEqual(new WorkoutTime(ClockTime::at(6, 30), 30))
                ->and($day->workouts[0]->workingSets)->toBe(8)
                ->and($day->workouts[0]->intensity)->toBe(DayType::Light)
                ->and($day->day->type)->toBe(DayType::Light);
        } else {
            expect($day->workouts)->toBe([], "weekday {$weekday}")
                ->and($day->day->type)->toBe(DayType::Rest);
        }
    }
    expect($built->days[0]->workouts[0]->session->name)->toBe('A')
        ->and($built->days[2]->workouts[0]->session->name)->toBe('B')
        ->and($built->days[4]->workouts[0]->session->name)->toBe('C');
});

it('SCH-041 every day has its meals in time order and they add up to the day', function () {
    $built = F::build();

    foreach ($built->days as $day) {
        $offsets = F::offsets($day);
        $sorted = $offsets;
        sort($sorted);
        expect($day->meals)->toHaveCount(6, 'no shake for a 30 minute session')
            ->and($offsets)->toBe($sorted)
            ->and(array_unique($offsets))->toHaveCount(6, 'distinct times')
            ->and(array_map(fn (WeekMeal $meal) => $meal->meal->name, $day->meals))
            ->toBe(array_map(fn (int $n) => "Meal {$n}", range(1, 6)))
            ->and(array_unique(array_map(fn (WeekMeal $meal) => $meal->id(), $day->meals)))->toHaveCount(6);
        F::assertMealsSumToDay($day);
    }
    expect(array_map(fn (WeekMeal $meal) => $meal->kind, $built->days[1]->meals))
        ->each->toBe(WeekMealKind::Regular);
});

it('NUT-023 training days get more energy than rest days', function () {
    $kcal = F::kcal(F::build());

    // Monday (light, 30 min) against Tuesday: same steps and BMR, 135 kcal of training.
    expect($kcal[0] - $kcal[1])->toEqualWithDelta(F::workoutKcal(30, DayType::Light), F::ACC)
        ->and($kcal[0] - $kcal[1])->toEqualWithDelta(135, F::ACC)
        ->and($kcal[2])->toBeGreaterThan($kcal[3])
        ->and($kcal[1])->toEqualWithDelta($kcal[3], F::ACC)
        // The weekend step plan (1.375) is different from the weekdays (1.30).
        ->and($kcal[5])->toBeGreaterThan($kcal[1]);
});

it('NUT-020 the days add up to the week goal', function () {
    $built = F::build();
    $workoutDays = 3 * F::workoutKcal(30, DayType::Light);
    $expenditure = array_sum(array_map(fn (WeekDay $day) => F::restExpenditure($day->day->date), $built->days)) + $workoutDays;

    expect(array_sum(F::kcal($built)))->toEqualWithDelta($expenditure + 7 * F::FAT_LOSS_CHANGE, F::ACC)
        ->and($built->week->isDeload)->toBeFalse()
        ->and($built->week->isDietBreak)->toBeFalse()
        ->and($built->week->isAway)->toBeFalse();
});

it('SCH-043 a session over an hour gets an intra-workout shake', function () {
    // 22 working sets: 65 minutes, Hard.
    $long = F::session('Long', chest: 12, back: 8, traps: 2);
    $built = F::build(mesocycle: F::mesocycle(PhaseType::FatLoss, [$long, $long, $long]));

    $monday = $built->days[0];
    $offsets = F::offsets($monday);
    $sorted = $offsets;
    sort($sorted);
    $shakes = array_values(array_filter($monday->meals, fn (WeekMeal $meal) => $meal->kind === WeekMealKind::IntraWorkoutShake));
    $others = array_values(array_filter($monday->meals, fn (WeekMeal $meal) => $meal->kind !== WeekMealKind::IntraWorkoutShake));
    expect($monday->workouts[0]->time->lengthMinutes)->toBe(65)
        ->and($monday->workouts[0]->intensity)->toBe(DayType::Hard)
        ->and($monday->day->type)->toBe(DayType::Hard)
        ->and($monday->meals)->toHaveCount(7)
        ->and($shakes)->toHaveCount(1)
        ->and($shakes[0]->meal->name)->toBe('Intra-workout shake')
        ->and($shakes[0]->time)->toEqual(ClockTime::at(6, 30))
        ->and($offsets)->toBe($sorted)
        ->and(array_map(fn (WeekMeal $meal) => $meal->meal->name, $others))
        ->toBe(array_map(fn (int $n) => "Meal {$n}", range(1, 6)));
    F::assertMealsSumToDay($monday);
    expect($built->days[1]->meals)->toHaveCount(6, 'a rest day has no shake');
});

// End to end

it('e2e NUT-031 NUT-032 a fat loss week one sums and puts carbs around the workout and the deload week at maintenance', function () {
    $week1 = F::build(week: 0);

    // Totals sum, every day.
    foreach ($week1->days as $day) {
        F::assertMealsSumToDay($day);
    }

    // The spec's reference day: fat loss, 6 meals, a 30 minute session at 6:30 AM.
    $monday = $week1->days[0];
    expect(array_map(fn (WeekMeal $meal) => $meal->kind, $monday->meals))->toBe([
        WeekMealKind::PreWorkout, WeekMealKind::PostWorkout, WeekMealKind::Regular,
        WeekMealKind::Regular, WeekMealKind::Regular, WeekMealKind::Regular,
    ])
        ->and($monday->meals[0]->time)->toEqual(ClockTime::at(5))
        ->and($monday->meals[1]->time)->toEqual(ClockTime::at(7));

    // NUT-031: the post-workout meal carries more carbs than any other meal.
    $post = $monday->meals[1];
    foreach ($monday->meals as $meal) {
        if ($meal->id() !== $post->id()) {
            expect($post->meal->target->carbsG)->toBeGreaterThan($meal->meal->target->carbsG, $meal->meal->name);
        }
    }
    // NUT-032: the pre- and post-workout meals have the lowest fat. Grams round to 5, so a
    // regular meal can tie the pre or post one at the 5 g floor; none may be lower, and on
    // average the peri-workout meals sit clearly below the rest.
    $periFat = [$monday->meals[0]->meal->target->fatG, $monday->meals[1]->meal->target->fatG];
    $otherFat = array_map(fn (WeekMeal $meal) => $meal->meal->target->fatG, array_slice($monday->meals, 2));
    expect(max($periFat))->toBeLessThanOrEqual(min($otherFat))
        ->and(array_sum($periFat) / 2)->toBeLessThan(array_sum($otherFat) / 4)
        // Direction check against the NUT-037 reference: a Light training day is above a rest day.
        ->and($monday->target->kcal)->toBeGreaterThan($week1->days[1]->target->kcal);

    // The deload week of the same mesocycle runs at maintenance.
    $deload = F::build(week: 4);
    expect($deload->week->isDeload)->toBeTrue()
        ->and($deload->week->isDietBreak)->toBeTrue();
    // Deload sessions: chest 2 + back 1 = 3 sets, 20 minutes, Light.
    $deloadKcal = F::workoutKcal(20, DayType::Light);
    foreach ($deload->days as $day) {
        $workoutKcal = $day->workouts === [] ? 0 : $deloadKcal;
        expect($day->target->kcal)->toEqualWithDelta(F::restExpenditure($day->day->date) + $workoutKcal, F::ACC);
        F::assertMealsSumToDay($day);
    }
    expect(array_filter($deload->days, fn (WeekDay $day) => $day->workouts !== []))->toHaveCount(3);
});

// Deload and diet breaks

it('PLAN-024 a muscle gain deload is at maintenance but not a diet break', function () {
    $deload = F::build(plan: F::muscleGainPlan(), week: 4);
    $normal = F::build(plan: F::muscleGainPlan(), week: 0);

    expect($deload->week->isDeload)->toBeTrue()
        ->and($deload->week->isDietBreak)->toBeFalse();
    foreach ($deload->days as $day) {
        $workoutKcal = $day->workouts === [] ? 0 : F::workoutKcal(20, DayType::Light);
        expect($day->target->kcal)->toEqualWithDelta(F::restExpenditure($day->day->date) + $workoutKcal, F::ACC);
    }
    // A normal muscle gain week is a surplus on rest days.
    expect($normal->days[1]->target->kcal)
        ->toEqualWithDelta(F::restExpenditure($normal->days[1]->day->date) + F::MUSCLE_GAIN_CHANGE, F::ACC)
        ->and($normal->week->isDeload)->toBeFalse();
});

it('PLAN-023 a fat loss deload with the diet break turned off runs in a deficit', function () {
    $atMaintenance = F::build(week: 4);
    $off = F::build(week: 4, dietBreakTurnedOff: true);

    // Still a deload week, with the same light sessions, but no diet break.
    expect($off->week->isDeload)->toBeTrue()
        ->and($off->week->isDietBreak)->toBeFalse();
    foreach ($off->days as $index => $deficit) {
        expect($deficit->target->kcal)
            ->toEqualWithDelta($atMaintenance->days[$index]->target->kcal + F::FAT_LOSS_CHANGE, F::ACC);
    }
});

it('PLAN-023 turning the diet break off changes nothing outside fat loss and mini-cut', function () {
    $gain = F::build(plan: F::muscleGainPlan(), week: 4);
    $gainOff = F::build(plan: F::muscleGainPlan(), week: 4, dietBreakTurnedOff: true);
    expect(F::kcal($gainOff))->toBe(F::kcal($gain));

    // A normal week is not a deload, so there is no diet break to turn off.
    $normal = F::build(week: 0);
    $normalOff = F::build(week: 0, dietBreakTurnedOff: true);
    expect(F::kcal($normalOff))->toBe(F::kcal($normal));
});

it('PLAN-023 an unplanned diet break still runs at maintenance whatever the flag', function () {
    $planned = F::build(week: 4);
    $both = F::build(week: 4, isDietBreak: true, dietBreakTurnedOff: true);

    expect(F::kcal($both))->toBe(F::kcal($planned))
        ->and($both->week->isDietBreak)->toBeTrue();
});

it('PLAN-022 a maintenance phase deload is not a diet break', function () {
    $plan = PlanFixtures::plan([PlanFixtures::phase(PhaseType::Maintenance, 0, 8, 0)]);
    $deload = F::build(plan: $plan, week: 4);

    expect($deload->week->isDeload)->toBeTrue()
        ->and($deload->week->isDietBreak)->toBeFalse();
});

it('NUT-023 deload sessions are halved without traps or forearms and light', function () {
    // Normal: 22 working sets is a Hard session. Deload: Light whatever the sets.
    $long = F::session('Long', chest: 12, back: 8, traps: 2);
    $mesocycle = F::mesocycle(PhaseType::FatLoss, [$long, $long, $long]);

    $normal = F::build(mesocycle: $mesocycle, week: 0);
    expect($normal->days[0]->workouts[0]->intensity)->toBe(DayType::Hard)
        ->and($normal->days[0]->workouts[0]->session->slots)->toHaveCount(3);

    $deload = F::build(mesocycle: $mesocycle, week: 4);
    $workout = $deload->days[0]->workouts[0];
    expect(array_map(fn (ExerciseSlot $slot) => $slot->muscle, $workout->session->slots))->toBe([MuscleGroup::Chest, MuscleGroup::Back])
        ->and(array_map(fn (ExerciseSlot $slot) => $slot->sets, $workout->session->slots))->toBe([6, 4])
        ->and($workout->workingSets)->toBe(10)
        ->and($workout->time->lengthMinutes)->toBe(35)
        ->and($workout->intensity)->toBe(DayType::Light)
        ->and($deload->days[0]->day->type)->toBe(DayType::Light);
});

it('PLAN-022 an unplanned diet break is at maintenance', function () {
    $built = F::build(week: 1, isDietBreak: true);

    expect($built->week->isDietBreak)->toBeTrue()
        ->and($built->week->isDeload)->toBeFalse();
    foreach ($built->days as $day) {
        $workoutKcal = $day->workouts === [] ? 0 : F::workoutKcal(30, DayType::Light);
        expect($day->target->kcal)->toEqualWithDelta(F::restExpenditure($day->day->date) + $workoutKcal, F::ACC);
    }
    expect(array_filter($built->days, fn (WeekDay $day) => $day->workouts !== []))->toHaveCount(3, 'training continues');
});

// Away weeks

it('SCH-060 a maintenance away week in fat loss is a diet break without workouts', function () {
    $built = F::build(week: 1, away: new AwayWeek(Training::Pause, Calories::Maintenance));

    expect($built->week->isAway)->toBeTrue()
        ->and($built->week->isDietBreak)->toBeTrue()
        ->and($built->week->isDeload)->toBeFalse();
    foreach ($built->days as $day) {
        expect($day->workouts)->toBe([])
            ->and($day->day->type)->toBe(DayType::Rest)
            ->and($day->target->kcal)->toEqualWithDelta(F::restExpenditure($day->day->date), F::ACC)
            ->and($day->meals)->toHaveCount(6);
        F::assertMealsSumToDay($day);
    }
});

it('SCH-060 a maintenance away week in muscle gain is not a diet break', function () {
    $built = F::build(plan: F::muscleGainPlan(), week: 1, away: new AwayWeek(Training::Pause, Calories::Maintenance));

    expect($built->week->isAway)->toBeTrue()
        ->and($built->week->isDietBreak)->toBeFalse();
    foreach ($built->days as $day) {
        expect($day->target->kcal)->toEqualWithDelta(F::restExpenditure($day->day->date), F::ACC);
    }
});

it('SCH-060 rest days calories follow the rest day energy plus the phase change', function () {
    $built = F::build(week: 1, away: new AwayWeek(Training::Pause, Calories::RestDays));

    expect($built->week->isAway)->toBeTrue()
        ->and($built->week->isDietBreak)->toBeFalse('still in a deficit');
    foreach ($built->days as $day) {
        expect($day->workouts)->toBe([])
            ->and($day->target->kcal)->toEqualWithDelta(F::restExpenditure($day->day->date) + F::FAT_LOSS_CHANGE, F::ACC);
    }
});

it('SCH-060 a paused away week on the deload week is not a deload', function () {
    $built = F::build(week: 4, away: new AwayWeek(Training::Pause, Calories::RestDays));

    expect($built->week->isAway)->toBeTrue()
        ->and($built->week->isDeload)->toBeFalse('a pause stops the mesocycle; the deload has not happened')
        ->and($built->week->isDietBreak)->toBeFalse();
    foreach ($built->days as $day) {
        expect($day->workouts)->toBe([])
            ->and($day->target->kcal)->toEqualWithDelta(F::restExpenditure($day->day->date) + F::FAT_LOSS_CHANGE, F::ACC);
    }
});

it('SCH-060 a deload away week keeps deloaded light sessions', function () {
    $built = F::build(week: 1, away: new AwayWeek(Training::Deload, Calories::RestDays));

    expect($built->week->isDeload)->toBeTrue()
        ->and($built->week->isAway)->toBeTrue();
    $trained = array_values(array_filter($built->days, fn (WeekDay $day) => $day->workouts !== []));
    expect($trained)->toHaveCount(3);
    foreach (array_merge(...array_map(fn (WeekDay $day) => $day->workouts, $trained)) as $workout) {
        expect($workout->intensity)->toBe(DayType::Light)
            ->and($workout->workingSets)->toBe(3)
            ->and($workout->time->lengthMinutes)->toBe(20);
    }
    // "Rest days" calories: the training adds no workout energy, so a training day eats like the rest day beside it.
    foreach ($built->days as $day) {
        expect($day->target->kcal)->toEqualWithDelta(F::restExpenditure($day->day->date), F::ACC);
    }
});

// Workout position when peri-workout meals do not fit

/**
 * Monday's day, with one 8 set (30 minute) session at $start and the given busy periods.
 *
 * @param  list<ClockRange>  $busy
 */
function weekBuilderMondayWorkout(ClockTime $start, array $busy = []): WeekDay
{
    $nutrition = new NutritionSchedule(steps: F::steps(), eatingWindow: F::window(), mealsPerDay: 6, busy: $busy);
    $schedule = new WeekSchedule([new TrainingDay(F::MONDAY, new WorkoutTime($start, 0))], $nutrition);
    $day = F::build(schedule: $schedule)->days[0];
    expect($day->workouts[0]->time)->toEqual(new WorkoutTime($start, 30));

    return $day;
}

it('SCH-042 only a pre-workout meal fits: the last meal before the workout takes the post share too', function () {
    // Ends 21:15: the post-workout range 21:15 to 22:15 is outside the 05:00 to 21:00 window.
    $day = weekBuilderMondayWorkout(ClockTime::at(20, 45));

    $pres = array_values(array_filter($day->meals, fn (WeekMeal $meal) => $meal->kind === WeekMealKind::PreWorkout));
    expect($day->meals)->toHaveCount(6)
        ->and(array_filter($day->meals, fn (WeekMeal $meal) => $meal->kind === WeekMealKind::PostWorkout))->toBe([])
        ->and($pres)->toHaveCount(1);
    $pre = $pres[0];
    expect($pre->id())->toBe($day->meals[5]->id(), 'the pre-workout meal is the last meal, just before the workout')
        ->and(F::offset($pre->time))->toBeLessThanOrEqual(F::offset(ClockTime::at(20, 45)));
    foreach ($day->meals as $meal) {
        if ($meal->id() !== $pre->id()) {
            expect($pre->meal->target->carbsG)->toBeGreaterThan($meal->meal->target->carbsG, $meal->meal->name);
        }
    }
    // The workout comes after the last meal, so no other meal is pre-workout: the rest all carry the
    // same rest share (to the 5 g rounding), not a pre-workout share on the meal before the last.
    $others = array_slice(F::carbs($day), 0, -1);
    expect(max($others) - min($others))->toBeLessThanOrEqual(5);
    F::assertMealsSumToDay($day);
});

it('SCH-042 no peri-workout meal fits after the window: the last meal is treated as pre-workout', function () {
    // 00:30 is nearer the window's end than its start. The pre range 21:30 to 23:30 and the post
    // range 01:00 to 02:00 are outside the window. (At 22:00 a pre meal fits on the 21:00 edge.)
    $day = weekBuilderMondayWorkout(ClockTime::at(0, 30));

    expect($day->meals)->toHaveCount(6)
        ->and(array_map(fn (WeekMeal $meal) => $meal->kind, $day->meals))->each->toBe(WeekMealKind::Regular);
    $last = $day->meals[5];
    foreach (array_slice($day->meals, 0, -1) as $meal) {
        expect($last->meal->target->carbsG)->toBeGreaterThan($meal->meal->target->carbsG, $meal->meal->name);
    }
    F::assertMealsSumToDay($day);
});

it('SCH-042 no peri-workout meal fits before the window: the first meal is treated as post-workout', function () {
    // Pre range 00:00 to 02:00 and post range 03:30 to 04:30 are both outside 05:00 to 21:00.
    $day = weekBuilderMondayWorkout(ClockTime::at(3));

    expect($day->meals)->toHaveCount(6)
        ->and(array_map(fn (WeekMeal $meal) => $meal->kind, $day->meals))->each->toBe(WeekMealKind::Regular);
    $first = $day->meals[0];
    foreach (array_slice($day->meals, 1) as $meal) {
        expect($first->meal->target->carbsG)->toBeGreaterThan($meal->meal->target->carbsG, $meal->meal->name);
    }
    F::assertMealsSumToDay($day);
});

// Training only

it('SCH-041 a day outside the busy weekdays is placed as if no busy period applied', function () {
    $lunch = new ClockRange(ClockTime::at(9), ClockTime::at(12));
    $times = fn (NutritionSchedule $nutrition) => array_map(
        fn (WeekDay $day) => array_map(fn (WeekMeal $meal) => $meal->time, $day->meals),
        F::build(schedule: F::schedule($nutrition))->days,
    );
    $plain = $times(F::nutrition());
    // Busy periods are keyed by ISO weekday (5 = Friday), as the server stores weekdays.
    $friday = new NutritionSchedule(
        steps: F::steps(), eatingWindow: F::window(), mealsPerDay: 6, busyByWeekday: [5 => [$lunch]],
    );
    $busy = $times($friday);

    // The week starts on Monday, so Friday is index 4.
    expect($busy[4])->not->toEqual($plain[4])
        ->and(array_slice($busy, 0, 4))->toEqual(array_slice($plain, 0, 4))
        ->and(array_slice($busy, 5))->toEqual(array_slice($plain, 5));
});

it('SAFE-003 training only returns workouts and no nutrition', function () {
    $built = F::build(schedule: F::schedule(trainingOnly: true));

    expect($built->days)->toHaveCount(7)
        ->and(array_filter($built->days, fn (WeekDay $day) => $day->workouts !== []))->toHaveCount(3)
        ->and($built->days[0]->workouts[0]->time->lengthMinutes)->toBe(30)
        ->and($built->days[0]->day->type)->toBe(DayType::Light);
    foreach ($built->days as $day) {
        expect($day->meals)->toBe([])
            ->and($day->target)->toBeNull()
            ->and($day->carbBoundsWarning)->toBeNull();
    }
});

// Refusals

it('PLAN-002 a week no phase covers is refused', function () {
    expect(F::refusal(fn () => F::build(week: 8))->reason)->toBe(WeekBuildRefusalReason::NoPhase)
        ->and(F::refusal(fn () => F::build(week: 20))->reason)->toBe(WeekBuildRefusalReason::NoPhase);
});

it('SCH-041 a window too small for the meals is refused', function () {
    $tight = new ClockRange(ClockTime::at(5), ClockTime::at(9));
    $refusal = F::refusal(fn () => F::build(schedule: F::schedule(F::nutrition(window: $tight))));

    expect($refusal->reason)->toBe(WeekBuildRefusalReason::MealsDoNotFit)
        ->and($refusal->dayIndex)->toBe(0);
});

it('SAFE-011 a BMI at the floor refuses the cut', function () {
    $thin = F::profile(weightKg: 50);
    $refusal = F::refusal(fn () => F::build(profile: $thin));

    expect($refusal->reason)->toBe(WeekBuildRefusalReason::Targets)
        ->and($refusal->targets?->reason)->toBe(TargetRefusalReason::BmiAtOrBelowFloor)
        ->and($refusal->targets?->bmi)->toEqualWithDelta(50 / (1.8 * 1.8), 1e-9);
    // Training-only stops before any nutrition rule.
    expect(F::build(profile: $thin, schedule: F::schedule(trainingOnly: true))->days)->toHaveCount(7);
});

// Protein boost

it('NUT-011 protein is boosted in the first weeks of muscle gain after fat loss', function () {
    $plan = PlanFixtures::plan([
        PlanFixtures::phase(PhaseType::FatLoss, 0, 4, -0.75),
        PlanFixtures::phase(PhaseType::MuscleGain, 4, 12, 0.25),
    ]);
    $mesocycle = F::mesocycle(PhaseType::MuscleGain);

    $first = F::build(plan: $plan, mesocycle: $mesocycle, mesocycleStartWeek: 4, week: 4);
    $second = F::build(plan: $plan, mesocycle: $mesocycle, mesocycleStartWeek: 4, week: 5);
    $third = F::build(plan: $plan, mesocycle: $mesocycle, mesocycleStartWeek: 4, week: 6);

    // 2.2 g/kg x 90 kg = 198 g; boosted x1.5 = 297 g, which is the 3.3 g/kg cap.
    expect($first->phase->type)->toBe(PhaseType::MuscleGain)
        ->and($first->days[0]->target->proteinG)->toEqualWithDelta(297, F::ACC)
        ->and($second->days[0]->target->proteinG)->toEqualWithDelta(297, F::ACC)
        ->and($third->days[0]->target->proteinG)->toEqualWithDelta(198, F::ACC);
    foreach ($first->days as $day) {
        F::assertMealsSumToDay($day);
    }
});

it('NUT-011 no boost when muscle gain is the first phase', function () {
    $built = F::build(plan: F::muscleGainPlan(), week: 0);

    expect($built->days[0]->target->proteinG)->toEqualWithDelta(198, F::ACC);
});

// SCH-039

it('SCH-039 a keep-all-sessions mesocycle builds each week\'s sessions and ends on the deload', function () {
    // A, B, C on 3 days, cut to Monday and Wednesday from week 1: weeks 1 to 3 held 9 sessions.
    $mesocycle = TrainingWeekdays::keepingAllSessions(F::mesocycle(PhaseType::FatLoss), daysPerWeek: 2, fromWeek: 1);
    expect($mesocycle->weeks)->toBe(7);
    $schedule = new WeekSchedule(array_slice(F::trainingDays(), 0, 2), F::nutrition());

    $sessions = fn (int $week) => array_map(
        fn (WeekWorkout $workout) => $workout->session->name,
        array_merge(...array_map(fn (WeekDay $day) => $day->workouts, F::build(mesocycle: $mesocycle, week: $week, schedule: $schedule)->days)),
    );
    expect($sessions(0))->toBe(['A', 'B'])
        ->and($sessions(1))->toBe(['A', 'B'])
        ->and($sessions(2))->toBe(['C', 'A'])
        ->and($sessions(3))->toBe(['B', 'C'])
        ->and($sessions(5))->toBe(['C'])
        ->and(F::build(mesocycle: $mesocycle, week: 5, schedule: $schedule)->week->isDeload)->toBeFalse()
        ->and(F::build(mesocycle: $mesocycle, week: 6, schedule: $schedule)->week->isDeload)->toBeTrue();
});

// A chosen calorie average (ONB-017)

it('ONB-017 no change builds the same week as before', function () {
    $plain = F::build();
    $unchanged = F::build(calorieChangePerDay: 0);
    $carbs = fn ($built) => array_map(fn (WeekDay $day) => $day->target->carbsG, $built->days);

    expect(F::kcal($unchanged))->toBe(F::kcal($plain))
        ->and($carbs($unchanged))->toBe($carbs($plain));
});

it('ONB-017 a cut moves every day down by the same calories and keeps protein', function () {
    $plain = F::build();
    $cut = F::build(calorieChangePerDay: -250);

    foreach ($plain->days as $index => $before) {
        $after = $cut->days[$index];
        expect($after->target->kcal)->toEqualWithDelta($before->target->kcal - 250, 1e-6)
            ->and($after->target->proteinG)->toEqualWithDelta($before->target->proteinG, 1e-6);
    }
});

it('ONB-017 an increase moves every day up by the same calories', function () {
    $plain = F::build();
    $more = F::build(calorieChangePerDay: 300);

    foreach ($plain->days as $index => $before) {
        expect($more->days[$index]->target->kcal)->toEqualWithDelta($before->target->kcal + 300, 1e-6);
    }
});

it('ONB-017 the meals still add up to the changed day', function () {
    $built = F::build(calorieChangePerDay: -250);

    foreach ($built->days as $day) {
        $meals = array_sum(array_map(fn (WeekMeal $meal) => $meal->meal->target->kcal, $day->meals));
        expect($meals)->toEqualWithDelta($day->target->kcal, 1e-6);
    }
});

it('ONB-017 a cut below the carb minimum is refused', function () {
    $refusal = F::refusal(fn () => F::build(calorieChangePerDay: -5_000));

    expect($refusal->reason)->toBe(WeekBuildRefusalReason::Targets)
        ->and($refusal->targets?->reason)->toBe(TargetRefusalReason::BreaksFloor);
});

// SCH-032, SCH-038 Other activities

it('SCH-038 NUT-003 a rest day with an other activity gets its energy and the day type', function () {
    $activity = new OtherActivity('Run', 60, DayType::Moderate);
    $plain = F::build();
    $with = F::build(otherActivities: ['2026-01-06' => $activity]);

    $energy = F::workoutKcal(60, DayType::Moderate);
    expect($with->days[1]->target->kcal - $plain->days[1]->target->kcal)->toEqualWithDelta($energy, F::ACC)
        ->and($with->days[1]->target->carbsG)->toBeGreaterThan($plain->days[1]->target->carbsG)
        ->and($with->days[1]->day->type)->toBe(DayType::Moderate)
        ->and($with->days[3]->target->kcal)->toEqualWithDelta($plain->days[3]->target->kcal, F::ACC, 'the other days are the same');
    // The meals still add up to the day.
    F::assertMealsSumToDay($with->days[1]);
});

it('SCH-038 a training day with a hard activity is hard and keeps its meal times', function () {
    $activity = new OtherActivity('HIIT', 30, DayType::Hard);
    $plain = F::build();
    $with = F::build(otherActivities: ['2026-01-05' => $activity]);

    $times = fn (WeekDay $day) => array_map(fn (WeekMeal $meal) => $meal->time, $day->meals);
    $kinds = fn (WeekDay $day) => array_map(fn (WeekMeal $meal) => $meal->kind, $day->meals);
    expect($with->days[0]->day->type)->toBe(DayType::Hard)
        // Peri-workout meals stay around the mesocycle session only.
        ->and($times($with->days[0]))->toEqual($times($plain->days[0]))
        ->and($kinds($with->days[0]))->toBe($kinds($plain->days[0]))
        ->and(array_map(fn (WeekWorkout $w) => $w->time, $with->days[0]->workouts))
        ->toEqual(array_map(fn (WeekWorkout $w) => $w->time, $plain->days[0]->workouts))
        ->and(array_map(fn (WeekWorkout $w) => $w->intensity, $with->days[0]->workouts))
        ->toBe(array_map(fn (WeekWorkout $w) => $w->intensity, $plain->days[0]->workouts));
    $energy = F::workoutKcal(30, DayType::Hard);
    expect($with->days[0]->target->kcal - $plain->days[0]->target->kcal)->toEqualWithDelta($energy, F::ACC);
});

// Port decisions and inputs the Swift suite does not cover

it('SCH-041 SCH-043 every meal of the week takes its own id from newId', function () {
    // 22 working sets: each training day has an intra-workout shake, so 7 x 6 meals and 3 shakes.
    $long = F::session('Long', chest: 12, back: 8, traps: 2);
    $built = F::build(mesocycle: F::mesocycle(PhaseType::FatLoss, [$long, $long, $long]));

    $ids = array_merge(...array_map(fn (WeekDay $day) => array_map(fn (WeekMeal $meal) => $meal->id(), $day->meals), $built->days));
    expect($ids)->toEqualCanonicalizing(array_map(fn (int $n) => "n{$n}", range(1, 45)));
});

it('SCH-038 a plan outside UTC reads its days and other activities in its own time zone', function () {
    // A Brisbane plan (UTC+10) from Monday 5 January; the run is on Tuesday 6 in Brisbane.
    $grid = new PlanWeekGrid(PlanFixtures::date(2026, 1, 5, zone: 'Australia/Brisbane'), PlanFixtures::MONDAY);
    $plan = PlanFixtures::plan([PlanFixtures::phase(PhaseType::FatLoss, 0, 8, -0.75, $grid)], $grid);
    $built = F::build(plan: $plan, otherActivities: ['2026-01-06' => new OtherActivity('Run', 60, DayType::Moderate)]);

    expect($built->days[0]->day->date)->toEqual(PlanFixtures::date(2026, 1, 5, zone: 'Australia/Brisbane'))
        ->and($built->days[0]->workouts)->toHaveCount(1)
        ->and($built->days[1]->day->type)->toBe(DayType::Moderate)
        ->and($built->days[2]->day->type)->toBe(DayType::Light);
});

it('SCH-053 a skipped session trains nothing and its day is a rest day', function () {
    // B is day 2 of the mesocycle's first week, on Wednesday.
    $plain = F::build();
    $skipped = F::build(sessionRows: [new SessionRow(week: 1, day: 2, start: null, isSkipped: true)]);

    expect($skipped->days[2]->workouts)->toBe([])
        ->and($skipped->days[2]->day->type)->toBe(DayType::Rest)
        ->and($skipped->days[2]->target->kcal)->toEqualWithDelta($plain->days[3]->target->kcal, F::ACC)
        ->and($skipped->days[0]->workouts[0]->session->name)->toBe('A');
    F::assertMealsSumToDay($skipped->days[2]);
});

it('NUT-021 a calorie move shifts one day to another and the meals follow', function () {
    $plain = F::build();
    $moved = F::build(moves: [new CalorieMove(from: 1, to: 5, kcal: 100)]);

    expect($moved->days[1]->target->kcal)->toEqualWithDelta($plain->days[1]->target->kcal - 100, F::ACC)
        ->and($moved->days[5]->target->kcal)->toEqualWithDelta($plain->days[5]->target->kcal + 100, F::ACC)
        ->and(array_sum(F::kcal($moved)))->toEqualWithDelta(array_sum(F::kcal($plain)), F::ACC);
    F::assertMealsSumToDay($moved->days[1]);
    F::assertMealsSumToDay($moved->days[5]);
});

it('NUT-022 a move past the band is refused as a move', function () {
    $refusal = F::refusal(fn () => F::build(moves: [new CalorieMove(from: 1, to: 5, kcal: 2_000)]));

    expect($refusal->reason)->toBe(WeekBuildRefusalReason::Move)
        ->and($refusal->move?->reason)->toBe(MoveRefusalReason::OutsideBand);
});
