<?php

use App\Actions\Onboarding\StartRows;
use App\Domain\Onboarding\BusyPeriodChoice;
use App\Domain\Onboarding\TrainingPlanKind;
use App\Domain\Safety\ScreeningItem;
use Tests\Unit\Domain\Onboarding\OnboardingFixtures as F;

// ONB-020: the rows "Start my plan" saves, from what the answers describe (OnboardingStartTest). The fixture plan
// starts on Sunday 11 October 2026 and reviews on Saturdays.

it('ONB-020 the profile row carries every answer, the schedule settings and the storage defaults', function () {
    $draft = F::atReview()->with(
        eatingStartMinutes: 6 * 60 + 30,
        busyPeriods: [new BusyPeriodChoice('busy-1', 'Busy', 12 * 60, 13 * 60, [1, 2, 3, 4, 5])],
    );

    expect(StartRows::profile(F::start($draft)))->toBe([
        'name' => 'Sam',
        'sex' => 'female',
        'birth_date' => '1990-04-09',
        'height_cm' => 170.0,
        'weight_kg' => 80.0,
        'body_fat_bucket' => 'low',
        'uses_glp1' => false,
        'experience' => 'intermediate',
        'weight_unit' => 'kg',
        'height_unit' => 'cm',
        'appearance' => 'system',
        'consumed_totals' => 'planned',
        'eating_window' => ['start' => '06:30', 'end' => '20:00'],
        'meal_count' => 5,
        'busy_periods' => [['label' => 'Busy', 'start' => '12:00', 'end' => '13:00', 'weekdays' => [1, 2, 3, 4, 5]]],
        'training_days' => [
            ['weekday' => 1, 'start' => '18:00', 'minutes' => 60],
            ['weekday' => 3, 'start' => '18:00', 'minutes' => 60],
            ['weekday' => 5, 'start' => '18:00', 'minutes' => 60],
        ],
        'weigh_in_days' => [
            ['weekday' => 1, 'time' => '07:00'], ['weekday' => 3, 'time' => '07:00'], ['weekday' => 5, 'time' => '07:00'],
        ],
        'step_plan' => [
            ['weekday' => 1, 'min' => 5000, 'max' => 8000], ['weekday' => 2, 'min' => 5000, 'max' => 8000],
            ['weekday' => 3, 'min' => 5000, 'max' => 8000], ['weekday' => 4, 'min' => 5000, 'max' => 8000],
            ['weekday' => 5, 'min' => 5000, 'max' => 8000], ['weekday' => 6, 'min' => 8000, 'max' => 10000],
            ['weekday' => 7, 'min' => 8000, 'max' => 10000],
        ],
        'diet_filters' => [],
        'screening' => [],
        'training_only' => false,
    ]);
});

it('ONB-005 SAFE-001 a training-only profile row saves the measured screening and the skipped steps\' defaults', function () {
    $row = StartRows::profile(F::start(F::atTrainingOnlyReview()->with(eatingStartMinutes: 5 * 60, mealCount: 3)));

    expect($row['training_only'])->toBeTrue()
        ->and($row['screening'])->toBe([ScreeningItem::Diabetes->value])
        ->and($row['body_fat_bucket'])->toBeNull()
        ->and($row['uses_glp1'])->toBeNull()
        ->and($row['eating_window'])->toBe(['start' => '07:00', 'end' => '20:00'])
        ->and($row['meal_count'])->toBe(5)
        ->and($row['busy_periods'])->toBe([]);
});

it('ONB-020 the plan and phase rows start on the plan\'s first day', function () {
    $start = F::start(F::atReview());

    expect(StartRows::plan($start))->toBe(['start_date' => '2026-10-11', 'review_weekday' => 6])
        ->and(StartRows::phase($start))->toBe([
            'id' => $start->phase->id, 'type' => 'fatLoss', 'start_date' => '2026-10-11', 'end_date' => '2027-01-02',
            'target_rate_percent_per_week' => -0.75,
        ]);
});

it('ONB-012 the mesocycle row holds the template\'s sessions and exercises', function () {
    $start = F::start(F::atReview());
    $meso = StartRows::mesocycle($start);
    $slots = array_merge(...array_column($meso['days'], 'slots'));

    expect($meso)->toMatchArray([
        'phase_id' => $start->phase->id, 'name' => 'Full body three', 'weeks' => 5, 'start_date' => '2026-10-11',
        'is_draft' => false, 'is_resensitization' => false, 'route' => 'template',
        'muscle_priorities' => ['chest' => 'grow', 'sideDelts' => 'emphasize'],
    ])
        ->and(array_column($meso['days'], 'label'))->toBe(['Day 1', 'Day 2', 'Day 3'])
        ->and(array_column($meso['days'], 'weekday'))->toBe([1, 3, 5])
        ->and(array_column($meso['days'][0]['slots'], 'exercise_id'))->toBe([101, 201])
        ->and(array_column($meso['days'][2]['slots'], 'exercise_id'))->toBe([103, 203])
        ->and(array_column($meso['days'][0]['slots'], 'muscle_group'))->toBe(['chest', 'sideDelts'])
        ->and(array_column($meso['days'][0]['slots'], 'sets'))->toBe([3, 2])
        ->and(array_unique(array_column($slots, 'id')))->toHaveCount(6);
});

it('ONB-012 "build it later" and the Meso Builder save a draft mesocycle row without exercises', function (TrainingPlanKind $kind, string $route) {
    $start = F::start(F::atReview()->with(trainingPlan: $kind, templateId: null));
    $meso = StartRows::mesocycle($start);

    expect($meso)->toMatchArray([
        'is_draft' => true, 'route' => $route, 'weeks' => 5, 'phase_id' => $start->phase->id, 'muscle_priorities' => null,
    ])
        ->and(array_column($meso['days'], 'label'))->toBe(['Workout 1', 'Workout 2', 'Workout 3'])
        ->and(array_column($meso['days'], 'weekday'))->toBe([1, 3, 5])
        ->and(array_merge(...array_column($meso['days'], 'slots')))->toBe([]);
})->with([
    'build later' => [TrainingPlanKind::Later, 'scratch'],
    'Meso Builder' => [TrainingPlanKind::MesoBuilder, 'builder'],
]);

it('ONB-020 the plan week and day target rows carry week one with its meals, each with its own id', function () {
    $start = F::start(F::atReview());
    $ids = 0;
    $days = StartRows::dayTargets($start, function () use (&$ids) {
        $ids++;

        return "day-{$ids}";
    });

    expect(StartRows::planWeek($start, 'week-0'))
        ->toBe(['id' => 'week-0', 'start_date' => '2026-10-11', 'is_deload' => false, 'is_diet_break' => false, 'is_away' => false])
        ->and(array_column($days, 'id'))->toBe(['day-1', 'day-2', 'day-3', 'day-4', 'day-5', 'day-6'])
        ->and(array_column($days, 'date'))->toBe(['2026-10-11', '2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16'])
        ->and(array_column($days, 'day_type'))->toContain('rest');
    foreach ($days as $day) {
        expect(array_sum(array_column($day['meals'], 'kcal')))->toEqualWithDelta($day['kcal'], 1e-6);
        foreach ($day['meals'] as $meal) {
            expect($meal['time'])->toMatch('/^\d\d:\d\d$/');
        }
    }
});
