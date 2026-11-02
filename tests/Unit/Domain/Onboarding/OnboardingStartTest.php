<?php

use App\Domain\Model\MuscleGroup;
use App\Domain\Onboarding\BusyPeriodChoice;
use App\Domain\Onboarding\Draft;
use App\Domain\Onboarding\OnboardingCalories;
use App\Domain\Onboarding\OnboardingStart;
use App\Domain\Onboarding\OnboardingTemplate;
use App\Domain\Onboarding\OnboardingTemplateDay;
use App\Domain\Onboarding\OnboardingTemplateSlot;
use App\Domain\Onboarding\TrainingPlanKind;
use App\Domain\Onboarding\WeighInChoice;
use App\Domain\Safety\ScreeningItem;
use App\Enums\PhaseFit;
use App\Enums\SexFit;
use App\Enums\Split;
use Tests\Unit\Domain\Onboarding\OnboardingFixtures as F;

// "Start my plan" (ONB-020), ported from rp-clone's OnboardingStartTests: what the answers save. The server writes it
// all in one transaction and deletes the draft, so the launch ids, the retry ids and the stale days of the Swift tests
// have nothing to port. The fixture plan starts on Sunday 11 October 2026 and reviews on Saturdays, so its first week
// runs Sunday to Friday (PLAN-008).

/**
 * The review templates with exercise ids: day d's slots are exercises 100 + d and 200 + d.
 *
 * @return list<OnboardingTemplate>
 */
function startCatalogue(): array
{
    $template = fn (int $id, string $name, Split $split, array $phaseFit) => new OnboardingTemplate(
        id: $id, name: $name, split: $split, daysPerWeek: 3, sex: SexFit::Any, phaseFit: $phaseFit,
        priorities: ['chest' => 'grow', 'sideDelts' => 'emphasize'],
        days: array_map(fn (int $day) => new OnboardingTemplateDay("Day {$day}", [
            new OnboardingTemplateSlot("Bench press {$day}", MuscleGroup::Chest, 3, 100 + $day),
            new OnboardingTemplateSlot("Lateral raise {$day}", MuscleGroup::SideDelts, 2, 200 + $day),
        ]), [1, 2, 3]),
    );

    return [
        $template(2, 'Full body three', Split::FullBody, [PhaseFit::Cut, PhaseFit::Maintain]),
        $template(3, 'Delts three', Split::Specialisation, [PhaseFit::Gain, PhaseFit::Cut, PhaseFit::Maintain]),
    ];
}

function startPlan(Draft $draft, ?DateTimeImmutable $today = null): OnboardingStart
{
    $ids = 0;

    return OnboardingStart::from($draft, $today ?? F::today(), startCatalogue(), function () use (&$ids) {
        $ids++;

        return sprintf('00000000-0000-4000-8000-%012d', $ids);
    });
}

function startRefusal(Draft $draft): ?string
{
    try {
        startPlan($draft);
    } catch (DomainException $refusal) {
        return $refusal->getMessage();
    }

    return null;
}

it('ONB-020 the profile carries every answer and the schedule settings', function () {
    $draft = F::atReview()->with(
        eatingStartMinutes: 6 * 60 + 30,
        busyPeriods: [new BusyPeriodChoice('busy-1', 'Busy', 12 * 60, 13 * 60, [1, 2, 3, 4, 5])],
    );

    expect(startPlan($draft)->profile)->toBe([
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

it('SAFE-001 the screening answers are saved with the measured items, in the spec\'s order', function () {
    $draft = F::atReview()->with(screening: [ScreeningItem::TryingToConceive], birthDate: F::date(1950, 1, 1));

    expect(startPlan($draft)->profile['screening'])->toBe(['ageOutOfRange', 'tryingToConceive'])
        ->and(startPlan($draft)->profile['training_only'])->toBeTrue();
});

it('ONB-005 SAFE-003 a training-only setup saves the mode, no body fat, drug answer or targets, and the defaults of the steps it skipped', function () {
    $start = startPlan(F::atTrainingOnlyReview()->with(eatingStartMinutes: 5 * 60, mealCount: 3));

    expect($start->profile['training_only'])->toBeTrue()
        ->and($start->profile['screening'])->toBe(['diabetes'])
        ->and($start->profile['body_fat_bucket'])->toBeNull()
        ->and($start->profile['uses_glp1'])->toBeNull()
        ->and($start->profile['eating_window'])->toBe(['start' => '07:00', 'end' => '20:00'])
        ->and($start->profile['meal_count'])->toBe(5)
        ->and($start->profile['busy_periods'])->toBe([])
        ->and($start->profile['step_plan'])->toHaveCount(7)
        ->and($start->dayTargets)->toBe([])
        ->and($start->phase['type'])->toBe('muscleGain')
        ->and($start->phase['target_rate_percent_per_week'])->toBe(0.0);
});

it('ONB-020 ONB-016 the plan starts on the chosen date and reviews on the review day', function () {
    expect(startPlan(F::atReview())->plan)->toBe(['start_date' => '2026-10-11', 'review_weekday' => 6]);
});

it('ONB-020 the first phase is step 6\'s phase on the plan-week grid', function () {
    $phase = startPlan(F::atReview())->phase;

    expect($phase)->toMatchArray([
        'type' => 'fatLoss',
        'start_date' => '2026-10-11',
        // 12 plan weeks: a short first week (Sun 11 to Fri 16 Oct), then weeks from Saturday 17 October.
        'end_date' => '2027-01-02',
        'target_rate_percent_per_week' => -0.75,
    ])->and($phase['id'])->toBeString();
});

it('ONB-012 the first mesocycle holds the template\'s sessions and exercises', function () {
    $start = startPlan(F::atReview());
    $meso = $start->mesocycle;
    $slots = array_merge(...array_column($meso['days'], 'slots'));

    expect($meso)->toMatchArray([
        'phase_id' => $start->phase['id'], 'name' => 'Full body three', 'weeks' => 5, 'start_date' => '2026-10-11',
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

it('ONB-012 MESO-003 a template without priorities leaves them to the phase', function () {
    $catalogue = startCatalogue();
    $bare = new OnboardingTemplate(2, 'Full body three', Split::FullBody, 3, SexFit::Any, $catalogue[0]->phaseFit, [], $catalogue[0]->days);
    $priorities = OnboardingStart::from(F::atReview(), F::today(), [$bare], fn () => 'id')->mesocycle['muscle_priorities'];

    expect($priorities)->toHaveCount(count(MuscleGroup::cases()))
        ->and(array_unique(array_values($priorities)))->toHaveCount(1);
});

it('ONB-012 "build it later" and the Meso Builder save a draft mesocycle without exercises', function (TrainingPlanKind $kind, string $route) {
    $start = startPlan(F::atReview()->with(trainingPlan: $kind, templateId: null));
    $meso = $start->mesocycle;

    expect($meso)->toMatchArray([
        'is_draft' => true, 'route' => $route, 'weeks' => 5, 'phase_id' => $start->phase['id'], 'muscle_priorities' => null,
    ])
        ->and(array_column($meso['days'], 'label'))->toBe(['Workout 1', 'Workout 2', 'Workout 3'])
        ->and(array_column($meso['days'], 'weekday'))->toBe([1, 3, 5])
        ->and(array_merge(...array_column($meso['days'], 'slots')))->toBe([]);
})->with([
    'build later' => [TrainingPlanKind::Later, 'scratch'],
    'Meso Builder' => [TrainingPlanKind::MesoBuilder, 'builder'],
]);

it('ONB-020 week one is saved with a day target for each of its days', function () {
    $start = startPlan(F::atReview());

    expect($start->planWeek)->toMatchArray(['start_date' => '2026-10-11', 'is_deload' => false, 'is_diet_break' => false, 'is_away' => false])
        // Sunday to Friday: the week is short because the review day is Saturday.
        ->and(array_column($start->dayTargets, 'date'))->toBe(['2026-10-11', '2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16'])
        // Monday, Wednesday and Friday are training days; the others are rest days.
        ->and(array_map(fn (array $day) => $day['day_type'] === 'rest', $start->dayTargets))->toBe([true, false, true, false, true, false])
        ->and(array_unique(array_column($start->dayTargets, 'id')))->toHaveCount(6);
});

it('ONB-020 each day target carries its meals, which add up to the day', function () {
    $start = startPlan(F::atReview());

    foreach ($start->dayTargets as $day) {
        $meals = $day['meals'];
        expect(count($meals))->toBeGreaterThanOrEqual(5)
            ->and(array_sum(array_column($meals, 'kcal')))->toEqualWithDelta($day['kcal'], 1e-6)
            ->and(array_sum(array_column($meals, 'protein_g')))->toEqualWithDelta($day['protein_g'], 1e-6)
            ->and(array_sum(array_column($meals, 'fat_g')))->toEqualWithDelta($day['fat_g'], 1e-6)
            ->and(array_sum(array_column($meals, 'carbs_g')))->toEqualWithDelta($day['carbs_g'], 1e-6)
            ->and($meals[0]['name'])->toStartWith('Meal');
        foreach ($meals as $meal) {
            expect($meal['time'])->toMatch('/^\d\d:\d\d$/');
        }
    }
    $ids = array_merge(...array_map(fn (array $day) => array_column($day['meals'], 'id'), $start->dayTargets));
    expect(array_unique($ids))->toHaveCount(count($ids));
});

it('ONB-017 a chosen average moves week one by its difference from the recommendation', function () {
    $plain = startPlan(F::atReview());
    $recommended = (new OnboardingCalories(F::atReview(), F::today(), startCatalogue()))->recommended()->averageKcal;
    $own = (int) round($recommended) + 150;
    $chosen = startPlan(F::atReview()->with(choosesOwnCalories: true, ownDailyKcal: $own));

    foreach ($chosen->dayTargets as $index => $day) {
        expect($day['kcal'])->toEqualWithDelta($plain->dayTargets[$index]['kcal'] + $own - $recommended, 1e-6)
            ->and($day['protein_g'])->toEqualWithDelta($plain->dayTargets[$index]['protein_g'], 1e-6)
            ->and(array_sum(array_column($day['meals'], 'kcal')))->toEqualWithDelta($day['kcal'], 1e-6);
    }
});

it('WT-001 ONB-015 the weigh-in schedule gives weekdays that share a time one entry', function () {
    $draft = F::atReview()->with(weighIns: [
        new WeighInChoice(1, 7 * 60), new WeighInChoice(3, 7 * 60), new WeighInChoice(5, 6 * 60 + 15), new WeighInChoice(7, 7 * 60),
    ]);

    expect(startPlan($draft)->weighInSchedule)->toBe([
        ['weekdays' => [1, 3, 7], 'time' => '07:00'],
        ['weekdays' => [5], 'time' => '06:15'],
    ]);
});

it('ONB-020 a start date in the past moves to tomorrow, keeping the length', function () {
    // Five days after the fixture's today: the saved start, Sunday 11 October, has passed.
    $start = startPlan(F::atReview(), F::date(2026, 10, 15)->setTime(9, 0));

    expect($start->plan['start_date'])->toBe('2026-10-16')
        ->and($start->phase['start_date'])->toBe('2026-10-16')
        ->and($start->mesocycle['start_date'])->toBe('2026-10-16')
        ->and($start->planWeek['start_date'])->toBe('2026-10-16')
        ->and($start->dayTargets[0]['date'])->toBe('2026-10-16')
        // Still 12 plan weeks: a one-day first week (Friday 16), then 11 from Saturday 17 October.
        ->and($start->phase['end_date'])->toBe('2027-01-02');
});

it('ONB-020 a start date of tomorrow or later is left alone', function () {
    expect(startPlan(F::atReview()->with(startDate: F::date(2026, 10, 13)))->plan['start_date'])->toBe('2026-10-13');
});

it('ONB-020 refuses with the first step\'s hint while a step\'s answers cannot continue', function () {
    expect(startRefusal(F::atReview()->with(experience: null)))->toBe('Pick your training experience to continue.')
        ->and(startRefusal(F::atReview()->with(weighIns: F::weighIns([1]))))->toBe('Too few weigh-ins to continue')
        ->and(startRefusal(new Draft))->toBe('Pick a goal and answer the question to continue.')
        ->and(startRefusal(F::atReview()))->toBeNull();
});

it('ONB-017 refuses with step 13\'s message when the chosen calories cannot be planned', function () {
    expect(startRefusal(F::atReview()->with(choosesOwnCalories: true, ownDailyKcal: null)))->toBe(OnboardingCalories::OWN_CALORIES_MESSAGE)
        ->and(startRefusal(F::atReview()->with(choosesOwnCalories: true, ownDailyKcal: 600)))
        ->toBe('That is too few calories to fit your protein, fat and carbs. Choose a higher average.');
});

it('ONB-005 a training-only setup is not refused for the nutrition steps it skipped', function () {
    expect(startRefusal(F::atTrainingOnlyReview()))->toBeNull();
});
