<?php

use App\Domain\Macros\MoveRefusal;
use App\Domain\Macros\TargetRefusal;
use App\Domain\Model\BodyFatBucket;
use App\Domain\Model\ExerciseSlot;
use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\HeightUnit;
use App\Domain\Model\Mesocycle;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\Phase;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Plan;
use App\Domain\Model\Profile;
use App\Domain\Model\Session;
use App\Domain\Model\Sex;
use App\Domain\Model\StepPlanDay;
use App\Domain\Model\WeightUnit;
use App\Domain\Placement\ClockRange;
use App\Domain\Placement\ClockTime;
use App\Domain\Placement\TrainingDay;
use App\Domain\Placement\WorkoutTime;
use App\Domain\Plan\PlanRebuild;
use App\Domain\Plan\PlanRebuildRefusal;
use App\Domain\Plan\PlanRebuildRefusal\Reason;
use App\Domain\Plan\PlanRebuildRefused;
use App\Domain\WeekBuilder\MealNames;
use App\Domain\WeekBuilder\NutritionSchedule;
use App\Domain\WeekBuilder\WeekBuildRefusal;
use App\Domain\WeekBuilder\WeekDay;
use App\Domain\WeekBuilder\WeekMeal;
use App\Domain\WeekBuilder\WeekMealKind;
use App\Domain\WeekBuilder\WeekSchedule;
use Tests\Unit\Domain\Plan\PlanFixtures;

// The shared world (rp-clone PlanRebuildTests): the plan starts Monday 2026-09-07 and reviews on Mondays, so the week
// of Oct 12 to 18 is week 5. One Fat loss phase with no end. Sam trains Thursdays at 17:30 and eats 07:00 to 21:00.

function rebuildPlan(bool $withPhase = true): Plan
{
    $start = PlanFixtures::date(2026, 9, 7);

    return new Plan(
        id: 'plan', start: $start, reviewWeekday: PlanFixtures::MONDAY,
        phases: $withPhase ? [new Phase('phase', PhaseType::FatLoss, $start, null, -0.5)] : [],
    );
}

function rebuildProfile(float $weightKg = 64): Profile
{
    return new Profile(
        name: 'Sam', sex: Sex::Female, birthDate: PlanFixtures::date(1990, 4, 12), heightCm: 168, weightKg: $weightKg,
        weightUnit: WeightUnit::Kg, heightUnit: HeightUnit::Cm, bodyFatBucket: BodyFatBucket::Low, usesGLP1: false,
        experience: ExperienceLevel::Intermediate,
    );
}

/**
 * @param  array<int, list<ClockRange>>  $busy  by ISO weekday
 * @param  list<TrainingDay>|null  $trainingDays  Thursdays at 17:30 unless given
 */
function rebuildSchedule(
    array $busy = [], ?ClockRange $window = null, int $meals = 4, ?array $trainingDays = null, bool $trainingOnly = false,
): WeekSchedule {
    $days = $trainingDays ?? [new TrainingDay(PlanFixtures::THURSDAY, new WorkoutTime(ClockTime::at(17, 30), 60))];

    return new WeekSchedule($days, $trainingOnly ? null : new NutritionSchedule(
        steps: array_map(fn (int $iso) => new StepPlanDay($iso, 6000, 10000), range(1, 7)),
        eatingWindow: $window ?? new ClockRange(ClockTime::at(7), ClockTime::at(21)),
        mealsPerDay: $meals,
        busyByWeekday: array_replace(array_fill(1, 7, []), $busy),
    ));
}

/** A mesocycle of the phase's type with one chest day of 12 sets and no workouts otherwise. */
function rebuildMesocycle(): Mesocycle
{
    return new Mesocycle(
        id: 'meso', phase: PhaseType::FatLoss, weeks: 4, musclePriorities: [],
        sessions: [new Session('push', 'Push', [new ExerciseSlot('slot', 'Bench press', MuscleGroup::Chest, 12)])],
    );
}

/** A mesocycle with one 22-set session: over an hour, so the training day gets an intra-workout shake (SCH-043). */
function rebuildLongMesocycle(): Mesocycle
{
    return new Mesocycle(
        id: 'meso', phase: PhaseType::FatLoss, weeks: 4, musclePriorities: [],
        sessions: [new Session('long', 'Long', [
            new ExerciseSlot('chest', 'Bench press', MuscleGroup::Chest, 12),
            new ExerciseSlot('back', 'Row', MuscleGroup::Back, 8),
            new ExerciseSlot('traps', 'Shrug', MuscleGroup::Traps, 2),
        ])],
    );
}

/**
 * @param  list<array{id: string, name: string}>  $stored
 * @return array<string, mixed> named arguments of PlanRebuild::days() with the long session on Thursday 15 Oct
 */
function rebuildShakeWith(array $stored): array
{
    return ['mesocycle' => rebuildLongMesocycle(), 'mesocycleStartWeek' => 5, 'storedMeals' => ['2026-10-15' => $stored]];
}

/**
 * @param  list<string>  $ids
 * @return list<array{id: string, name: string}>
 */
function rebuildStoredMeals(array $ids, ?string $shakeId = null): array
{
    $meals = array_map(fn (string $id) => ['id' => $id, 'name' => 'Meal'], $ids);
    if ($shakeId !== null) {
        $meals[] = ['id' => $shakeId, 'name' => MealNames::INTRA_WORKOUT_SHAKE];
    }

    return $meals;
}

/**
 * @param  list<WeekDay>  $days
 * @return list<WeekMeal>
 */
function rebuildDayMeals(array $days, string $date): array
{
    return array_values(array_filter($days, fn (WeekDay $d) => $d->day->date->format('Y-m-d') === $date))[0]->meals;
}

/** A fresh id for every meal the builder makes: "new-1", "new-2", ... */
function rebuildNewId(): Closure
{
    $next = 0;

    return function () use (&$next): string {
        return 'new-'.++$next;
    };
}

/**
 * @param  array<string, mixed>  $with  named arguments of PlanRebuild::days()
 * @return list<WeekDay>
 */
function rebuildDays(array $with = []): array
{
    return PlanRebuild::days(...array_replace([
        'plan' => rebuildPlan(),
        'profile' => rebuildProfile(),
        'mesocycle' => new Mesocycle('', PhaseType::FatLoss, 0, [], []),
        'mesocycleStartWeek' => 0,
        'week' => 5,
        'schedule' => rebuildSchedule(),
        'today' => PlanFixtures::date(2026, 10, 14),
        'newId' => rebuildNewId(),
    ], $with));
}

/**
 * @param  list<WeekDay>  $days
 * @return list<string>
 */
function rebuildDates(array $days): array
{
    return array_map(fn (WeekDay $day) => $day->day->date->format('Y-m-d'), $days);
}

/**
 * @param  list<WeekDay>  $days
 * @return list<string>
 */
function rebuildMealIds(array $days, string $date): array
{
    $day = array_values(array_filter($days, fn (WeekDay $d) => $d->day->date->format('Y-m-d') === $date))[0];

    return array_map(fn (WeekMeal $meal) => $meal->id(), $day->meals);
}

/**
 * @param  list<WeekDay>  $days
 * @return list<string>
 */
function rebuildMealTimes(array $days, string $date): array
{
    $day = array_values(array_filter($days, fn (WeekDay $d) => $d->day->date->format('Y-m-d') === $date))[0];

    return array_map(fn (WeekMeal $meal) => (string) $meal->time, $day->meals);
}

function rebuildRefusal(Closure $call): PlanRebuildRefusal
{
    try {
        $call();
    } catch (PlanRebuildRefused $refused) {
        return $refused->refusal;
    }

    throw new RuntimeException('Expected a refusal.');
}

it('SET-004 writes tomorrow through the end of the week only', function () {
    // Wednesday 14 Oct: Monday and Tuesday are behind, today keeps its targets.
    expect(rebuildDates(rebuildDays()))->toBe(['2026-10-15', '2026-10-16', '2026-10-17', '2026-10-18']);
});

it('SET-004 today and earlier days never get a write, even with stored targets', function () {
    $stored = [];
    foreach (['2026-10-12' => 'a', '2026-10-13' => 'b', '2026-10-14' => 'c'] as $date => $id) {
        $stored[$date] = rebuildStoredMeals([$id.'1', $id.'2', $id.'3', $id.'4']);
    }
    $days = rebuildDays(['storedMeals' => $stored]);
    $ids = array_merge(...array_map(fn (WeekDay $day) => array_map(fn (WeekMeal $meal) => $meal->id(), $day->meals), $days));
    $storedIds = array_merge(...array_map(fn (array $meals) => array_column($meals, 'id'), array_values($stored)));

    expect(array_filter(rebuildDates($days), fn (string $date) => $date <= '2026-10-14'))->toBe([])
        ->and(array_intersect($ids, $storedIds))->toBe([]);
});

it('NUT-030 each rebuilt day\'s meal targets add up to the day', function () {
    foreach (rebuildDays() as $day) {
        $sum = fn (Closure $field) => array_sum(array_map(fn (WeekMeal $meal) => $field($meal->meal->target), $day->meals));

        expect($day->meals)->toHaveCount(4)
            ->and($sum(fn ($t) => $t->kcal))->toEqualWithDelta($day->target->kcal, 4)
            ->and($sum(fn ($t) => $t->proteinG))->toEqualWithDelta($day->target->proteinG, 4)
            ->and($sum(fn ($t) => $t->fatG))->toEqualWithDelta($day->target->fatG, 4)
            ->and($sum(fn ($t) => $t->carbsG))->toEqualWithDelta($day->target->carbsG, 4);
    }
});

it('SET-004 on the last day of the week there is nothing to write', function () {
    expect(rebuildDays(['today' => PlanFixtures::date(2026, 10, 18)]))->toBe([]);
});

it('SET-004 a rebuilt day keeps the ids of its stored meals in the stored order', function () {
    // The array order is the stored position, whatever the times say.
    $days = rebuildDays(['storedMeals' => ['2026-10-15' => rebuildStoredMeals(['id-3', 'id-1', 'id-4', 'id-2'])]]);

    expect(rebuildMealIds($days, '2026-10-15'))->toBe(['id-3', 'id-1', 'id-4', 'id-2'])
        // A day with no stored target gets fresh ids.
        ->and(rebuildMealIds($days, '2026-10-16'))->each->toStartWith('new-');
});

it('NUT-030 a shake stored last keeps its id and the regular meals keep theirs in order', function () {
    $days = rebuildDays(rebuildShakeWith(rebuildStoredMeals(['m1', 'm2', 'm3', 'm4'], 's')));
    $meals = rebuildDayMeals($days, '2026-10-15');
    $regular = array_values(array_filter($meals, fn (WeekMeal $m) => $m->kind !== WeekMealKind::IntraWorkoutShake));
    $shake = array_values(array_filter($meals, fn (WeekMeal $m) => $m->kind === WeekMealKind::IntraWorkoutShake));

    expect($meals)->toHaveCount(5)
        ->and(array_map(fn (WeekMeal $m) => $m->id(), $regular))->toBe(['m1', 'm2', 'm3', 'm4'])
        ->and($shake)->toHaveCount(1)
        ->and($shake[0]->id())->toBe('s');
});

it('SET-004 a day that loses its shake drops only the shake id', function () {
    $days = rebuildDays(['storedMeals' => ['2026-10-15' => rebuildStoredMeals(['m1', 'm2', 'm3', 'm4'], 's')]]);

    expect(rebuildMealIds($days, '2026-10-15'))->toBe(['m1', 'm2', 'm3', 'm4']);
});

it('SET-004 a day that gains a shake gives it a new id and keeps the regular ids', function () {
    $days = rebuildDays(rebuildShakeWith(rebuildStoredMeals(['m1', 'm2', 'm3', 'm4'])));
    $meals = rebuildDayMeals($days, '2026-10-15');
    $regular = array_values(array_filter($meals, fn (WeekMeal $m) => $m->kind !== WeekMealKind::IntraWorkoutShake));
    $shake = array_values(array_filter($meals, fn (WeekMeal $m) => $m->kind === WeekMealKind::IntraWorkoutShake));

    expect(array_map(fn (WeekMeal $m) => $m->id(), $regular))->toBe(['m1', 'm2', 'm3', 'm4'])
        ->and($shake)->toHaveCount(1)
        ->and($shake[0]->id())->not->toBeIn(['m1', 'm2', 'm3', 'm4']);
});

it('SET-004 a window crossing midnight keeps the ids position by position', function () {
    $days = rebuildDays([
        'schedule' => rebuildSchedule(window: new ClockRange(ClockTime::at(20), ClockTime::at(2)), meals: 3, trainingDays: []),
        'storedMeals' => ['2026-10-15' => rebuildStoredMeals(['id-1', 'id-2', 'id-3'])],
    ]);

    expect(rebuildMealIds($days, '2026-10-15'))->toBe(['id-1', 'id-2', 'id-3']);
});

it('SET-004 fewer meals drop only the tail and more meals add new ids', function () {
    $days = rebuildDays(['storedMeals' => [
        '2026-10-15' => rebuildStoredMeals(['id-1', 'id-2', 'id-3', 'id-4', 'id-5', 'id-6']),
        '2026-10-16' => rebuildStoredMeals(['id-7', 'id-8']),
    ]]);

    $shorter = rebuildMealIds($days, '2026-10-15');
    $longer = rebuildMealIds($days, '2026-10-16');
    expect($shorter)->toBe(['id-1', 'id-2', 'id-3', 'id-4'])
        ->and($longer)->toHaveCount(4)
        ->and(array_slice($longer, 0, 2))->toBe(['id-7', 'id-8'])
        ->and(count(array_unique($longer)))->toBe(4);
});

it('SET-005 a weekday busy period does not move a Saturday\'s meals', function () {
    $work = new ClockRange(ClockTime::at(11), ClockTime::at(13));
    $plain = rebuildDays();
    $busy = rebuildDays(['schedule' => rebuildSchedule(busy: array_fill_keys([1, 2, 3, 4, 5], [$work]))]);

    // Friday the 16th is busy, so its meals move; Saturday the 17th and Sunday the 18th are untouched.
    expect(rebuildMealTimes($busy, '2026-10-16'))->not->toBe(rebuildMealTimes($plain, '2026-10-16'))
        ->and(rebuildMealTimes($busy, '2026-10-17'))->toBe(rebuildMealTimes($plain, '2026-10-17'))
        ->and(rebuildMealTimes($busy, '2026-10-18'))->toBe(rebuildMealTimes($plain, '2026-10-18'));
});

it('SET-005 each busy period only applies on its own weekdays', function () {
    $market = new ClockRange(ClockTime::at(11), ClockTime::at(13));
    $plain = rebuildDays();
    $busy = rebuildDays(['schedule' => rebuildSchedule(busy: [6 => [$market]])]);

    expect(rebuildMealTimes($busy, '2026-10-17'))->not->toBe(rebuildMealTimes($plain, '2026-10-17'))
        ->and(rebuildMealTimes($busy, '2026-10-16'))->toBe(rebuildMealTimes($plain, '2026-10-16'))
        ->and(rebuildMealTimes($busy, '2026-10-18'))->toBe(rebuildMealTimes($plain, '2026-10-18'));
});

it('SET-005 a busy period on other weekdays cannot refuse a Saturday that fits', function () {
    // 3 meals in 12:00 to 20:00. Mon to Fri are busy 12:00 to 16:00 and still fit (16:00, 18:00, 20:00).
    // Saturday trains at 16:00, so the same period would not fit there, but it does not apply on Saturday.
    $work = new ClockRange(ClockTime::at(12), ClockTime::at(16));
    $days = rebuildDays([
        'mesocycle' => rebuildMesocycle(),
        'mesocycleStartWeek' => 5,
        'schedule' => rebuildSchedule(
            busy: array_fill_keys([1, 2, 3, 4, 5], [$work]),
            window: new ClockRange(ClockTime::at(12), ClockTime::at(20)),
            meals: 3,
            trainingDays: [new TrainingDay(7, new WorkoutTime(ClockTime::at(16), 60))],
        ),
    ]);

    expect(rebuildDates($days))->toBe(['2026-10-15', '2026-10-16', '2026-10-17', '2026-10-18'])
        ->and(rebuildMealTimes($days, '2026-10-16'))->toBe(['16:00', '18:00', '20:00']);
});

it('SET-005 a busy period that leaves no room on its days is still refused', function () {
    $allDay = new ClockRange(ClockTime::at(7), ClockTime::at(21));
    $refusal = rebuildRefusal(fn () => rebuildDays(['schedule' => rebuildSchedule(busy: [6 => [$allDay]])]));

    expect($refusal->reason)->toBe(Reason::MealsDoNotFit);
});

it('SAFE-017 a training-only profile gets no target writes', function () {
    expect(rebuildDays(['schedule' => rebuildSchedule(trainingOnly: true)]))->toBe([]);
});

it('SAFE-017 a calorie change moves every written day', function () {
    $plain = rebuildDays();
    $lower = rebuildDays(['calorieChangePerDay' => -200]);

    expect(rebuildDates($lower))->toBe(rebuildDates($plain));
    foreach ($lower as $index => $day) {
        expect($day->target->kcal)->toEqualWithDelta($plain[$index]->target->kcal - 200, 5);
    }
});

it('SAFE-017 before the plan starts the first week can be built whole', function () {
    $today = PlanFixtures::date(2026, 9, 2);

    expect(rebuildRefusal(fn () => PlanRebuild::week(rebuildPlan(), $today))->reason)->toBe(Reason::PlanNotStarted)
        ->and(PlanRebuild::week(rebuildPlan(), $today, beforeStartBuildsFirstWeek: true))->toBe(0)
        ->and(rebuildDates(rebuildDays(['week' => 0, 'today' => $today])))->toBe([
            '2026-09-07', '2026-09-08', '2026-09-09', '2026-09-10', '2026-09-11', '2026-09-12', '2026-09-13',
        ]);
});

it('SET-004 the week to rebuild is the one holding today', function () {
    expect(PlanRebuild::week(rebuildPlan(), PlanFixtures::date(2026, 10, 14)))->toBe(5)
        ->and(PlanRebuild::week(rebuildPlan(), PlanFixtures::date(2026, 10, 18)))->toBe(5)
        ->and(PlanRebuild::week(rebuildPlan(), PlanFixtures::date(2026, 10, 19)))->toBe(6);
});

it('SET-004 every day carries targets and its meals', function () {
    foreach (rebuildDays() as $day) {
        expect($day->target)->not->toBeNull()
            ->and(array_map(fn (WeekMeal $meal) => $meal->meal->name, $day->meals))->toBe(['Meal 1', 'Meal 2', 'Meal 3', 'Meal 4'])
            ->and(array_map(fn (WeekMeal $meal) => (string) $meal->time, $day->meals))->each->toMatch('/^\d\d:\d\d$/')
            ->and($day->target->kcal)->toBeGreaterThan(1000)
            ->and($day->target->proteinG)->toBeGreaterThan(0);
    }
});

it('SET-004 a profile change changes the targets', function () {
    $light = rebuildDays(['profile' => rebuildProfile(60)]);
    $heavy = rebuildDays(['profile' => rebuildProfile(90)]);

    expect($heavy[0]->target->kcal)->toBeGreaterThan($light[0]->target->kcal);
});

it('SET-004 no phase covering the week is refused', function () {
    $refusal = rebuildRefusal(fn () => PlanRebuild::week(rebuildPlan(withPhase: false), PlanFixtures::date(2026, 10, 14)));

    expect($refusal->reason)->toBe(Reason::NoPhase);
});

it('SET-004 a week before the plan starts is refused', function () {
    $refusal = rebuildRefusal(fn () => PlanRebuild::week(rebuildPlan(), PlanFixtures::date(2026, 8, 20)));

    expect($refusal->reason)->toBe(Reason::PlanNotStarted);
});

it('SET-004 a build the target rules refuse is refused with the reason', function () {
    $lowBmi = rebuildRefusal(fn () => rebuildDays(['profile' => rebuildProfile(40)]));
    $belowFloor = rebuildRefusal(fn () => rebuildDays(['calorieChangePerDay' => -2000]));

    expect($lowBmi->reason)->toBe(Reason::BmiAtOrBelowFloor)
        ->and($belowFloor->reason)->toBe(Reason::BreaksFloor);
});

it('SET-004 each way a week build is refused maps to one reason', function () {
    expect(PlanRebuildRefusal::fromWeekBuild(WeekBuildRefusal::noPhase())->reason)->toBe(Reason::NoPhase)
        ->and(PlanRebuildRefusal::fromWeekBuild(WeekBuildRefusal::targets(TargetRefusal::bmiAtOrBelowFloor(16)))->reason)->toBe(Reason::BmiAtOrBelowFloor)
        ->and(PlanRebuildRefusal::fromWeekBuild(WeekBuildRefusal::targets(TargetRefusal::breaksFloor(2)))->reason)->toBe(Reason::BreaksFloor)
        ->and(PlanRebuildRefusal::fromWeekBuild(WeekBuildRefusal::mealsDoNotFit(1))->reason)->toBe(Reason::MealsDoNotFit)
        ->and(PlanRebuildRefusal::fromWeekBuild(WeekBuildRefusal::move(MoveRefusal::invalid()))->reason)->toBe(Reason::Unbalanced);
});

it('SET-004 every refusal has a message saying the targets were not changed', function () {
    $messages = [
        [PlanRebuildRefusal::planNotStarted(), 'Your plan has not started yet, so there are no targets to update. Your targets were not changed.'],
        [PlanRebuildRefusal::noPhase(), 'No phase covers this week, so your targets were not changed.'],
        [PlanRebuildRefusal::bmiAtOrBelowFloor(), 'Your BMI is too low to plan a cut. Your targets were not changed.'],
        [PlanRebuildRefusal::breaksFloor(), 'These details would take your carbs or fat below the safe minimum. Your targets were not changed.'],
        [PlanRebuildRefusal::unbalanced(), 'The days could not be balanced. Your targets were not changed.'],
        [PlanRebuildRefusal::mealsDoNotFit(), 'Your eating window cannot hold your meals. Your targets were not changed.'],
    ];

    expect(array_map(fn (array $pair) => $pair[0]->reason, $messages))->toBe(Reason::cases());
    foreach ($messages as [$refusal, $message]) {
        expect($refusal->message())->toBe($message)
            ->and((new PlanRebuildRefused($refusal))->getMessage())->toBe($message)
            ->and($refusal->message())->toContain('not changed');
    }
});
