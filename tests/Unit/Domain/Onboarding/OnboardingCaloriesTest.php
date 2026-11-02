<?php

use App\Domain\Macros\MoveRefusal;
use App\Domain\Macros\TargetRefusal;
use App\Domain\Model\PhaseType;
use App\Domain\Onboarding\CalorieDay;
use App\Domain\Onboarding\CalorieWeek;
use App\Domain\Onboarding\Draft;
use App\Domain\Onboarding\OnboardingCalories;
use App\Domain\WeekBuilder\WeekBuildRefusal;
use Tests\Unit\Domain\Onboarding\OnboardingFixtures as F;

// Step 13, calories (ONB-017), the read cases of rp-clone's OnboardingCaloriesTests. The person weighs 80 kg and
// starts a fat-loss phase on Sunday 11 October, weighing in on Monday, Wednesday and Friday, so the review day is
// Saturday and the first plan week is short: the step shows the first full week. Choosing my own calories or typing
// an average is #67's write; here the draft already holds it.

function onboardingCalories(Draft $draft): OnboardingCalories
{
    return new OnboardingCalories($draft, F::today(), F::catalogue());
}

/** @return list<string> */
function onboardingDayNames(CalorieWeek $week): array
{
    return array_map(fn (CalorieDay $day) => $day->name, $week->days);
}

it('ONB-017 the recommendation shows its daily average and the split of a plan week', function () {
    $recommended = onboardingCalories(F::atStep13())->recommended();
    $kcal = array_map(fn (CalorieDay $day) => $day->kcal, $recommended->days);

    expect(onboardingDayNames($recommended))->toBe(['Sat', 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri'])
        ->and($recommended->averageKcal)->toEqualWithDelta(array_sum($kcal) / 7, 1e-6)
        ->and($recommended->averageKcal)->toBeGreaterThan(1200)->toBeLessThan(4500)
        ->and(min($kcal))->toBeGreaterThan(0);
});

it('ONB-017 the recommendation is the default, so it is the selection and the step can continue', function () {
    $calories = onboardingCalories(F::atStep13());

    expect($calories->selected())->toEqual($calories->recommended())
        ->and($calories->message())->toBeNull()
        ->and($calories->isReady())->toBeTrue();
});

it('ONB-017 my own average starting from the recommended one selects about the same week', function () {
    $recommended = onboardingCalories(F::atStep13())->recommended();
    $own = onboardingCalories(F::atStep13()->with(choosesOwnCalories: true, ownDailyKcal: round($recommended->averageKcal)));

    expect($own->selected()->averageKcal)->toEqualWithDelta($recommended->averageKcal, 1.0)
        ->and($own->isReady())->toBeTrue();
});

it('ONB-017 a chosen average moves every day by the same calories and leaves the recommendation', function () {
    $recommended = onboardingCalories(F::atStep13())->recommended();
    $calories = onboardingCalories(F::atStep13()->with(choosesOwnCalories: true, ownDailyKcal: $recommended->averageKcal + 200));
    $selected = $calories->selected();

    expect($selected->averageKcal)->toEqualWithDelta($recommended->averageKcal + 200, 1e-6)
        ->and(onboardingDayNames($selected))->toBe(onboardingDayNames($recommended))
        ->and($calories->recommended())->toEqual($recommended)
        ->and($calories->message())->toBeNull()
        ->and($calories->isReady())->toBeTrue();
    foreach ($recommended->days as $index => $day) {
        expect($selected->days[$index]->kcal)->toEqualWithDelta($day->kcal + 200, 1e-6);
    }
});

it('ONB-017 an average the carb minimum cannot hold is refused and blocks continuing', function () {
    $calories = onboardingCalories(F::atStep13()->with(choosesOwnCalories: true, ownDailyKcal: 600.0));

    expect($calories->selected())->toBeNull()
        ->and($calories->message())->toBe('That is too few calories to fit your protein, fat and carbs. Choose a higher average.')
        ->and($calories->isReady())->toBeFalse()
        ->and($calories->recommended())->not->toBeNull();
});

it('ONB-017 an empty or zero chosen average asks for one', function () {
    $empty = onboardingCalories(F::atStep13()->with(choosesOwnCalories: true));
    $zero = onboardingCalories(F::atStep13()->with(choosesOwnCalories: true, ownDailyKcal: 0.0));

    expect($empty->message())->toBe('Enter your average daily calories to continue.')
        ->and($empty->isReady())->toBeFalse()
        ->and($zero->message())->toBe(OnboardingCalories::OWN_CALORIES_MESSAGE)
        ->and($zero->isReady())->toBeFalse()
        ->and(OnboardingCalories::MAXIMUM_OWN_DAILY_KCAL)->toBe(10000);
});

it('ONB-017 a plan starting on the review day shows its first week', function () {
    $recommended = onboardingCalories(F::atStep13()->with(startDate: F::date(2026, 10, 17)))->recommended();

    expect(onboardingDayNames($recommended))->toBe(['Sat', 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri']);
});

it('ONB-017 the recommendation follows the goal', function () {
    expect(onboardingCalories(F::atStep13(PhaseType::MuscleGain))->recommended()->averageKcal)
        ->toBeGreaterThan(onboardingCalories(F::atStep13(PhaseType::FatLoss))->recommended()->averageKcal);
});

it('ONB-017 training-only has no calories, and neither does a draft missing an answer the plan needs', function () {
    $trainingOnly = onboardingCalories(F::atTrainingOnlyReview());
    $missing = onboardingCalories(F::atStep13()->with(usesGLP1: null));

    expect($trainingOnly->recommended())->toBeNull()
        ->and($trainingOnly->selected())->toBeNull()
        ->and($trainingOnly->message())->toBeNull()
        ->and($trainingOnly->isReady())->toBeFalse()
        ->and($missing->recommended())->toBeNull()
        ->and($missing->isReady())->toBeFalse();
});

it('ONB-017 says in words why the rules refuse the week', function () {
    $calories = onboardingCalories(F::atStep13());
    $own = onboardingCalories(F::atStep13()->with(choosesOwnCalories: true, mealCount: 6));

    expect($calories->messageFor(WeekBuildRefusal::noPhase()))
        ->toBe('The plan has no phase for its first week. Go back to step 6 and check the dates.')
        ->and($calories->messageFor(WeekBuildRefusal::targets(TargetRefusal::bmiAtOrBelowFloor(16.9))))
        ->toBe('Your BMI is too low for calories below maintenance. Choose Maintenance or Muscle gain on step 1.')
        ->and($calories->messageFor(WeekBuildRefusal::targets(TargetRefusal::breaksFloor(0))))
        ->toBe('These targets would leave too few carbs. Choose a slower rate or a shorter phase on step 6.')
        ->and($own->messageFor(WeekBuildRefusal::targets(TargetRefusal::breaksFloor(0))))
        ->toBe('That is too few calories to fit your protein, fat and carbs. Choose a higher average.')
        ->and($own->messageFor(WeekBuildRefusal::mealsDoNotFit(0)))
        ->toBe("Your eating window can't hold 6 meals around your busy periods and workouts. Widen it, or have fewer meals, on step 11.")
        ->and($calories->messageFor(WeekBuildRefusal::move(MoveRefusal::invalid())))
        ->toBe("Those calories can't be moved between days.");
});
