<?php

use App\Domain\Model\DayType;
use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Priority;
use App\Domain\Model\Session;
use App\Domain\Onboarding\BusyPeriodChoice;
use App\Domain\Onboarding\Draft;
use App\Domain\Onboarding\OnboardingCalories;
use App\Domain\Onboarding\OnboardingStart;
use App\Domain\Onboarding\OnboardingTemplate;
use App\Domain\Onboarding\TrainingPlanKind;
use App\Domain\Onboarding\WeighInChoice;
use App\Domain\Safety\ScreeningItem;
use App\Domain\WeekBuilder\WeekDay;
use App\Domain\WeekBuilder\WeekMeal;
use App\Enums\MesocycleRoute;
use App\Enums\SexFit;
use App\Enums\Split;
use Tests\Unit\Domain\Onboarding\OnboardingFixtures as F;

// "Start my plan" (ONB-020), ported from rp-clone's OnboardingStartTests: what the answers save, as values. The rows
// they become are StartRowsTest's. The server writes it all in one transaction and deletes the draft, so the launch
// ids, the retry ids and the stale days of the Swift tests have nothing to port. The fixture plan starts on Sunday
// 11 October 2026 and reviews on Saturdays, so its first week runs Sunday to Friday (PLAN-008).

function startPlan(Draft $draft, ?DateTimeImmutable $today = null): OnboardingStart
{
    return F::start($draft, $today);
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
    $busy = new BusyPeriodChoice('busy-1', 'Busy', 12 * 60, 13 * 60, [1, 2, 3, 4, 5]);
    $draft = F::atReview()->with(eatingStartMinutes: 6 * 60 + 30, busyPeriods: [$busy]);
    $start = startPlan($draft);

    expect($start->draft)->toEqual($draft)
        ->and($start->inputs->profile->name)->toBe('Sam')
        ->and($start->inputs->profile->experience)->toBe(ExperienceLevel::Intermediate)
        ->and($start->trainingOnly)->toBeFalse()
        ->and($start->screening)->toBe([])
        ->and(array_map(fn ($range) => [$range->weekday, $range->min, $range->max], $start->stepRanges))->toBe([
            [1, 5000, 8000], [2, 5000, 8000], [3, 5000, 8000], [4, 5000, 8000], [5, 5000, 8000], [6, 8000, 10000], [7, 8000, 10000],
        ]);
});

it('SAFE-001 the screening answers are saved with the measured items, in the spec\'s order', function () {
    $start = startPlan(F::atReview()->with(screening: [ScreeningItem::TryingToConceive], birthDate: F::date(1950, 1, 1)));

    expect($start->screening)->toBe([ScreeningItem::AgeOutOfRange, ScreeningItem::TryingToConceive])
        ->and($start->trainingOnly)->toBeTrue();
});

it('ONB-005 SAFE-003 a training-only setup saves the mode, no body fat, drug answer or targets, and the defaults of the steps it skipped', function () {
    $start = startPlan(F::atTrainingOnlyReview()->with(eatingStartMinutes: 5 * 60, mealCount: 3));

    expect($start->trainingOnly)->toBeTrue()
        ->and($start->screening)->toBe([ScreeningItem::Diabetes])
        ->and($start->draft->bodyFatBucket)->toBeNull()
        ->and($start->draft->usesGLP1)->toBeNull()
        ->and([$start->draft->eatingStartMinutes, $start->draft->eatingEndMinutes])->toBe([7 * 60, 20 * 60])
        ->and($start->draft->mealCount)->toBe(5)
        ->and($start->draft->busyPeriods)->toBe([])
        ->and($start->stepRanges)->toHaveCount(7)
        ->and($start->targetDays)->toBe([])
        ->and($start->phase->type)->toBe(PhaseType::MuscleGain)
        ->and($start->phase->targetRatePercentPerWeek)->toBe(0.0);
});

it('ONB-020 ONB-016 the plan starts on the chosen date and reviews on the review day', function () {
    $start = startPlan(F::atReview());

    expect($start->inputs->plan->start->format('Y-m-d'))->toBe('2026-10-11')
        ->and($start->reviewWeekday)->toBe(6);
});

it('ONB-020 the first phase is step 6\'s phase on the plan-week grid', function () {
    $phase = startPlan(F::atReview())->phase;

    expect($phase->type)->toBe(PhaseType::FatLoss)
        ->and($phase->start->format('Y-m-d'))->toBe('2026-10-11')
        // 12 plan weeks: a short first week (Sun 11 to Fri 16 Oct), then weeks from Saturday 17 October.
        ->and($phase->end?->format('Y-m-d'))->toBe('2027-01-02')
        ->and($phase->targetRatePercentPerWeek)->toBe(-0.75)
        ->and($phase->id)->toBeString();
});

it('ONB-012 the first mesocycle takes the template\'s name, sessions and priorities', function () {
    $start = startPlan(F::atReview());

    expect($start->mesocycleName)->toBe('Full body three')
        ->and($start->route)->toBe(MesocycleRoute::Template)
        ->and($start->inputs->template?->id)->toBe(2)
        ->and($start->inputs->mesocycle->weeks)->toBe(5)
        ->and(array_map(fn (Session $session) => $session->name, $start->inputs->mesocycle->sessions))->toBe(['Day 1', 'Day 2', 'Day 3'])
        ->and($start->inputs->sessionWeekdays)->toBe([1, 3, 5])
        ->and($start->musclePriorities)->toBe(['chest' => Priority::Grow, 'sideDelts' => Priority::Emphasize]);
});

it('ONB-012 MESO-003 a template without priorities leaves them to the phase', function () {
    $catalogue = F::startCatalogue();
    $bare = new OnboardingTemplate(2, 'Full body three', Split::FullBody, 3, SexFit::Any, $catalogue[0]->phaseFit, [], $catalogue[0]->days);
    $priorities = OnboardingStart::from(F::atReview(), F::today(), [$bare], fn () => 'id')->musclePriorities;

    expect($priorities)->toHaveCount(count(MuscleGroup::cases()))
        ->and(array_unique(array_map(fn (Priority $priority) => $priority->value, $priorities)))->toHaveCount(1);
});

it('ONB-012 "build it later" and the Meso Builder save a draft mesocycle without a template', function (TrainingPlanKind $kind, MesocycleRoute $route) {
    $start = startPlan(F::atReview()->with(trainingPlan: $kind, templateId: null));

    expect($start->route)->toBe($route)
        ->and($start->inputs->template)->toBeNull()
        ->and($start->mesocycleName)->toBe(OnboardingStart::DRAFT_MESOCYCLE_NAME)
        ->and($start->musclePriorities)->toBeNull()
        ->and($start->inputs->mesocycle->weeks)->toBe(5)
        ->and(array_map(fn (Session $session) => $session->name, $start->inputs->mesocycle->sessions))->toBe(['Workout 1', 'Workout 2', 'Workout 3'])
        ->and($start->inputs->sessionWeekdays)->toBe([1, 3, 5]);
})->with([
    'build later' => [TrainingPlanKind::Later, MesocycleRoute::Scratch],
    'Meso Builder' => [TrainingPlanKind::MesoBuilder, MesocycleRoute::Builder],
]);

it('ONB-020 week one is saved with a day target for each of its days', function () {
    $start = startPlan(F::atReview());
    $week = $start->week->week;

    expect([$week->start->format('Y-m-d'), $week->isDeload, $week->isDietBreak, $week->isAway])->toBe(['2026-10-11', false, false, false])
        // Sunday to Friday: the week is short because the review day is Saturday.
        ->and(array_map(fn (WeekDay $day) => $day->day->date->format('Y-m-d'), $start->targetDays))
        ->toBe(['2026-10-11', '2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16'])
        ->and($start->weekDates())->toBe(['2026-10-11', '2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16'])
        // Monday, Wednesday and Friday are training days; the others are rest days.
        ->and(array_map(fn (WeekDay $day) => $day->day->type === DayType::Rest, $start->targetDays))->toBe([true, false, true, false, true, false]);
});

it('ONB-020 each day target carries its meals, which add up to the day', function () {
    $start = startPlan(F::atReview());

    foreach ($start->targetDays as $day) {
        $meals = array_map(fn (WeekMeal $meal) => $meal->meal->target, $day->meals);
        expect(count($meals))->toBeGreaterThanOrEqual(5)
            ->and(array_sum(array_column($meals, 'kcal')))->toEqualWithDelta($day->target->kcal, 1e-6)
            ->and(array_sum(array_column($meals, 'proteinG')))->toEqualWithDelta($day->target->proteinG, 1e-6)
            ->and(array_sum(array_column($meals, 'fatG')))->toEqualWithDelta($day->target->fatG, 1e-6)
            ->and(array_sum(array_column($meals, 'carbsG')))->toEqualWithDelta($day->target->carbsG, 1e-6)
            ->and($day->meals[0]->meal->name)->toStartWith('Meal');
    }
    $ids = array_merge(...array_map(fn (WeekDay $day) => array_map(fn (WeekMeal $meal) => $meal->id(), $day->meals), $start->targetDays));
    expect(array_unique($ids))->toHaveCount(count($ids));
});

it('ONB-017 a chosen average moves week one by its difference from the recommendation', function () {
    $plain = startPlan(F::atReview());
    $recommended = (new OnboardingCalories(F::atReview(), F::today(), F::startCatalogue()))->recommended()->averageKcal;
    $own = (int) round($recommended) + 150;
    $chosen = startPlan(F::atReview()->with(choosesOwnCalories: true, ownDailyKcal: $own));

    foreach ($chosen->targetDays as $index => $day) {
        expect($day->target->kcal)->toEqualWithDelta($plain->targetDays[$index]->target->kcal + $own - $recommended, 1e-6)
            ->and($day->target->proteinG)->toEqualWithDelta($plain->targetDays[$index]->target->proteinG, 1e-6)
            ->and(array_sum(array_map(fn (WeekMeal $meal) => $meal->meal->target->kcal, $day->meals)))->toEqualWithDelta($day->target->kcal, 1e-6);
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

    expect($start->inputs->plan->start->format('Y-m-d'))->toBe('2026-10-16')
        ->and($start->draft->startDate?->format('Y-m-d'))->toBe('2026-10-16')
        ->and($start->phase->start->format('Y-m-d'))->toBe('2026-10-16')
        ->and($start->week->week->start->format('Y-m-d'))->toBe('2026-10-16')
        ->and($start->targetDays[0]->day->date->format('Y-m-d'))->toBe('2026-10-16')
        // Still 12 plan weeks: a one-day first week (Friday 16), then 11 from Saturday 17 October.
        ->and($start->phase->end?->format('Y-m-d'))->toBe('2027-01-02');
});

it('ONB-020 a start date of tomorrow or later is left alone', function () {
    expect(startPlan(F::atReview()->with(startDate: F::date(2026, 10, 13)))->inputs->plan->start->format('Y-m-d'))->toBe('2026-10-13');
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
