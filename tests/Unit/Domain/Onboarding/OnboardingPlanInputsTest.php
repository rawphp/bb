<?php

use App\Domain\Model\BodyFatBucket;
use App\Domain\Model\ExerciseSlot;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Priority;
use App\Domain\Model\Session;
use App\Domain\Model\StepPlanDay;
use App\Domain\Onboarding\Draft;
use App\Domain\Onboarding\OnboardingPlanInputs;
use App\Domain\Onboarding\StepRangeChoice;
use App\Domain\Onboarding\TrainingPlanKind;
use App\Domain\Placement\TrainingDay;
use Tests\Unit\Domain\Onboarding\OnboardingFixtures as F;

// The plan the answers describe (rp-clone OnboardingModel+Plan's planInputs): what the week builder takes for step 13
// and what onboarding_complete saves. The fixture plan starts on Sunday 11 October 2026 and reviews on Saturdays, so
// its first week is short (PLAN-008). Weekdays: the answers and the step plan are ISO; the plan, the grid and the
// training days count 1 = Sunday.

function onboardingPlanInputs(Draft $draft): ?OnboardingPlanInputs
{
    $ids = 0;

    return OnboardingPlanInputs::from($draft, F::today(), F::catalogue(), function () use (&$ids) {
        $ids++;

        return "id-{$ids}";
    });
}

it('ONB-020 there is no plan until every answer it needs is in', function () {
    expect(onboardingPlanInputs(F::atStep13()))->not->toBeNull()
        ->and(onboardingPlanInputs(F::atStep13()->with(experience: null)))->toBeNull()
        ->and(onboardingPlanInputs(F::atStep13()->with(trainingDays: [])))->toBeNull()
        ->and(onboardingPlanInputs(F::atStep13()->with(startDate: null)))->toBeNull()
        ->and(onboardingPlanInputs(F::atStep13()->with(usesGLP1: null)))->toBeNull()
        ->and(onboardingPlanInputs(F::atStep13()->with(bodyFatBucket: null)))->toBeNull()
        ->and(onboardingPlanInputs(F::atStep13()->with(weeks: null)))->toBeNull()
        ->and(onboardingPlanInputs(F::atTrainingOnlyReview()))->not->toBeNull();
});

it('ONB-020 ONB-016 the plan starts on the chosen date and its weeks start on the review day', function () {
    $inputs = onboardingPlanInputs(F::atStep13());

    expect($inputs->plan->start)->toEqual(F::tomorrow())
        ->and($inputs->plan->reviewWeekday)->toBe(7)
        ->and($inputs->phase()->type)->toBe(PhaseType::FatLoss)
        ->and($inputs->phase()->start)->toEqual(F::tomorrow())
        ->and($inputs->phase()->end)->toEqual(F::date(2027, 1, 2))
        ->and($inputs->phase()->targetRatePercentPerWeek)->toBe(-0.75);
});

it('ONB-005 a training-only phase is one mesocycle long with no rate, and has no nutrition schedule', function () {
    $inputs = onboardingPlanInputs(F::atTrainingOnlyReview());

    expect($inputs->schedule->nutrition)->toBeNull()
        ->and($inputs->phase()->type)->toBe(PhaseType::MuscleGain)
        ->and($inputs->phase()->targetRatePercentPerWeek)->toBe(0.0)
        ->and($inputs->mesocycle->weeks)->toBe(6)
        ->and($inputs->plan->reviewWeekday)->toBe(7)
        ->and($inputs->phase()->end)->toEqual(F::date(2026, 11, 21))
        ->and($inputs->profile->bodyFatBucket)->toBe(BodyFatBucket::Low)
        ->and($inputs->profile->usesGLP1)->toBeFalse();
});

it('ONB-011 the training days keep their times, and the sessions fall in plan-week order from the review day', function () {
    $inputs = onboardingPlanInputs(F::atStep13());
    $fromWednesday = onboardingPlanInputs(F::atStep13()->with(reviewWeekday: 3));

    expect(array_map(fn (TrainingDay $day) => $day->weekday, $inputs->schedule->trainingDays))->toBe([2, 4, 6])
        ->and($inputs->schedule->trainingDays[0]->time->start->minuteOfDay)->toBe(18 * 60)
        ->and($inputs->schedule->trainingDays[0]->time->lengthMinutes)->toBe(60)
        ->and($inputs->sessionWeekdays)->toBe([1, 3, 5])
        ->and($fromWednesday->sessionWeekdays)->toBe([3, 5, 1]);
});

it('ONB-012 building later gives one empty session per training day', function () {
    $mesocycle = onboardingPlanInputs(F::atStep13())->mesocycle;

    expect(array_map(fn (Session $session) => $session->name, $mesocycle->sessions))->toBe(['Workout 1', 'Workout 2', 'Workout 3'])
        ->and(array_map(fn (Session $session) => $session->slots, $mesocycle->sessions))->toBe([[], [], []])
        ->and($mesocycle->weeks)->toBe(5)
        ->and($mesocycle->phase)->toBe(PhaseType::FatLoss)
        ->and($mesocycle->musclePriorities)->toBe([])
        ->and(onboardingPlanInputs(F::atStep13())->template)->toBeNull();
});

it('ONB-012 a template gives its days as sessions, with their exercises and muscle priorities', function () {
    $inputs = onboardingPlanInputs(F::atReview());
    $sessions = $inputs->mesocycle->sessions;

    expect($inputs->template?->id)->toBe(2)
        ->and(array_map(fn (Session $session) => $session->name, $sessions))->toBe(['Day 1', 'Day 2', 'Day 3'])
        ->and(array_map(fn (ExerciseSlot $slot) => [$slot->exerciseName, $slot->muscle, $slot->sets], $sessions[0]->slots))
        ->toBe([['Bench press 1', MuscleGroup::Chest, 3], ['Lateral raise 1', MuscleGroup::SideDelts, 2]])
        ->and($inputs->mesocycle->musclePriorities)->toBe(['chest' => Priority::Grow, 'sideDelts' => Priority::Emphasize]);
});

it('ONB-012 the recommended template stands in while nothing is picked', function () {
    expect(onboardingPlanInputs(F::atStep13()->with(trainingPlan: null))->template?->id)->toBe(2)
        ->and(onboardingPlanInputs(F::atStep13()->with(trainingPlan: TrainingPlanKind::MesoBuilder))->template)->toBeNull();
});

it('ONB-013 ONB-014 the nutrition schedule takes the step ranges by ISO weekday, the window and the meals', function () {
    $nutrition = onboardingPlanInputs(F::atStep13()->with(
        stepRanges: [new StepRangeChoice(6, 8000, 13000)], eatingStartMinutes: 6 * 60, mealCount: 4,
    ))->schedule->nutrition;

    expect(array_map(fn (StepPlanDay $day) => [$day->weekday, $day->min, $day->max], $nutrition->steps))->toBe([
        [1, 5000, 8000], [2, 5000, 8000], [3, 5000, 8000], [4, 5000, 8000], [5, 5000, 8000], [6, 8000, 13000], [7, 8000, 10000],
    ])
        ->and($nutrition->eatingWindow->start->minuteOfDay)->toBe(6 * 60)
        ->and($nutrition->mealsPerDay)->toBe(4)
        ->and($nutrition->busy)->toBe([]);
});

it('ONB-004 the profile holds the answers from steps 2 to 7, the name trimmed', function () {
    $profile = onboardingPlanInputs(F::atStep13()->with(name: '  Sam  '))->profile;

    expect($profile->name)->toBe('Sam')
        ->and($profile->birthDate)->toEqual(F::adultBirthDate())
        ->and($profile->heightCm)->toBe(170.0)
        ->and($profile->weightKg)->toBe(80.0)
        ->and($profile->bodyFatBucket)->toBe(BodyFatBucket::Low)
        ->and($profile->usesGLP1)->toBeFalse();
});

it('ONB-020 the first plan week builds, short when the plan starts off the review day', function () {
    $week = onboardingPlanInputs(F::atStep13())->buildWeek(0);

    expect($week->week->dayCount)->toBe(6)
        ->and($week->days)->toHaveCount(6)
        ->and($week->days[0]->target?->kcal)->toBeGreaterThan(0);
});
