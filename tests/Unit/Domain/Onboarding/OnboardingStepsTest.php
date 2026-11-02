<?php

use App\Domain\Model\BodyFatBucket;
use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\PhaseType;
use App\Domain\Onboarding\BusyPeriodChoice;
use App\Domain\Onboarding\Draft;
use App\Domain\Onboarding\OnboardingSteps;
use App\Domain\Onboarding\StepMove;
use App\Domain\Onboarding\StepRangeChoice;
use App\Domain\Onboarding\TrainingPlanKind;
use App\Domain\Safety\ScreeningItem;
use Tests\Unit\Domain\Onboarding\OnboardingFixtures as F;

// The step sequence (ONB-002 to ONB-018, UI-007), from rp-clone's OnboardingModel and OnboardingModelTests: which
// steps show, the counter, the button title, whether a step can continue and why not, and where Next, Back and an
// Edit link go. Next, Back and Edit only name the target; #67's write moves the draft there.

function onboardingSteps(Draft $draft, ?array $templates = null): OnboardingSteps
{
    return new OnboardingSteps($draft, F::today(), $templates ?? F::catalogue());
}

/** Asserts can_continue and continue_hint together. */
function expectOnboardingContinue(Draft $draft, bool $canContinue, ?string $hint, ?array $templates = null): void
{
    $steps = onboardingSteps($draft, $templates);
    expect($steps->canContinue())->toBe($canContinue)
        ->and($steps->continueHint())->toBe($hint);
}

// ONB-005 training-only skips the nutrition steps

it('ONB-005 a training-only draft counts "Step n of 7" and skips steps 4, 5, 6, 10, 11 and 13', function () {
    $draft = F::atStep7TrainingOnly();

    expect(onboardingSteps($draft)->visibleSteps())->toBe([1, 2, 3, 7, 8, 9, 12, 14])
        ->and(onboardingSteps($draft)->position()->counter())->toBe('Step 4 of 7')
        ->and(onboardingSteps($draft->with(experience: ExperienceLevel::Beginner))->next())->toEqual(new StepMove(8))
        ->and(onboardingSteps($draft->with(step: 8, experience: ExperienceLevel::Beginner, trainingDays: F::trainingDays([2])))->position()->counter())->toBe('Step 5 of 7')
        ->and(onboardingSteps(F::atTrainingOnlyReview()->with(step: 9))->position()->counter())->toBe('Step 6 of 7')
        ->and(onboardingSteps(F::atTrainingOnlyReview()->with(step: 9))->next())->toEqual(new StepMove(12))
        ->and(onboardingSteps(F::atTrainingOnlyReview()->with(step: 12))->next())->toEqual(new StepMove(14))
        ->and(onboardingSteps(F::atTrainingOnlyReview())->position()->counter())->toBe('Review')
        ->and(onboardingSteps(F::atTrainingOnlyReview())->position()->current)->toBe(7)
        ->and(onboardingSteps(F::atTrainingOnlyReview())->back())->toBe(12)
        ->and(onboardingSteps($draft)->back())->toBe(3);
});

it('ONB-005 on step 3 with Fat loss and only "trying to conceive" ticked, next returns step 1 with the goal cleared', function () {
    $draft = F::atStep3(PhaseType::FatLoss)->with(screening: [ScreeningItem::TryingToConceive]);

    expect(onboardingSteps($draft)->next())->toEqual(new StepMove(1, clearsGoal: true));
});

it('ONB-005 on step 3 nothing ticked leads to step 4, and a blocking item to step 7', function () {
    $blocked = F::atStep3()->with(screening: [ScreeningItem::Diabetes]);

    expect(onboardingSteps(F::atStep3())->next())->toEqual(new StepMove(4))
        ->and(onboardingSteps($blocked)->next())->toEqual(new StepMove(7))
        ->and(onboardingSteps($blocked)->position()->counter())->toBe('Step 3 of 13')
        ->and(onboardingSteps(F::atStep3(birthDate: F::minorBirthDate()))->next())->toEqual(new StepMove(7));
});

it('ONB-005 "trying to conceive" with Muscle gain or Maintenance continues to step 4', function () {
    foreach ([PhaseType::MuscleGain, PhaseType::Maintenance] as $goal) {
        expect(onboardingSteps(F::atStep3($goal)->with(screening: [ScreeningItem::TryingToConceive]))->next())->toEqual(new StepMove(4));
    }
});

it('ONB-005 "trying to conceive" with another blocking item is training-only and keeps Fat loss', function () {
    $draft = F::atStep3(PhaseType::FatLoss)->with(screening: [ScreeningItem::TryingToConceive, ScreeningItem::Diabetes]);

    expect(onboardingSteps($draft)->next())->toEqual(new StepMove(7));
});

it('ONB-005 Fat loss picked again after backing up from the screening cannot continue', function () {
    expect(onboardingSteps(F::atStep3()->with(step: 1, screening: [ScreeningItem::TryingToConceive]))->canContinue())->toBeFalse();
});

it('ONB-002 a resumed step that is no longer shown counts as the next one shown and moves on from there', function () {
    // Saved at step 5 at 65; reopened after turning 66, so steps 4 to 6 are gone.
    $draft = F::atStep6()->with(step: 5, birthDate: F::date(1960, 1, 1));

    expect(onboardingSteps($draft)->position()->current)->toBe(4)
        ->and(onboardingSteps($draft)->position()->total)->toBe(7)
        ->and(onboardingSteps($draft)->next())->toEqual(new StepMove(7))
        ->and(onboardingSteps($draft)->back())->toBe(3);
});

// UI-007 counter, Back and Next

it('UI-007 ONB-002 the counter shows thirteen steps until screening is passed training-only', function () {
    expect(onboardingSteps(new Draft)->position()->counter())->toBe('Step 1 of 13')
        ->and(onboardingSteps(F::atStep3())->position()->counter())->toBe('Step 3 of 13')
        ->and(onboardingSteps(F::atStep3()->with(step: 4))->position()->counter())->toBe('Step 4 of 13')
        ->and(onboardingSteps(F::atStep13())->position()->counter())->toBe('Step 13 of 13');
});

it('UI-007 Back is on every step but the first', function () {
    $steps = fn (int $step) => onboardingSteps(F::atStep6()->with(step: $step));

    expect($steps(1)->canGoBack())->toBeFalse()
        ->and($steps(1)->back())->toBeNull()
        ->and($steps(2)->canGoBack())->toBeTrue()
        ->and($steps(2)->back())->toBe(1)
        ->and($steps(6)->back())->toBe(5);
});

it('UI-007 Next goes nowhere until the step can continue', function () {
    expect(onboardingSteps(new Draft)->next())->toBeNull()
        ->and(onboardingSteps(new Draft(goal: PhaseType::FatLoss))->next())->toBeNull()
        ->and(onboardingSteps(F::atStep2()->with(step: 1))->next())->toEqual(new StepMove(2))
        ->and(onboardingSteps(F::atStep7())->next())->toBeNull();
});

it('UI-007 step 6 reads Continue, the review Start my plan, and every other step Next', function () {
    expect(onboardingSteps(F::atStep6())->nextTitle())->toBe('Continue')
        ->and(onboardingSteps(F::atStep6())->next())->toEqual(new StepMove(7))
        ->and(onboardingSteps(F::atReview())->nextTitle())->toBe('Start my plan')
        ->and(onboardingSteps(F::atStep6()->with(step: 5))->nextTitle())->toBe('Next')
        ->and(onboardingSteps(F::atStep7())->nextTitle())->toBe('Next');
});

it('UI-007 every step has its key and title', function () {
    expect(array_map(OnboardingSteps::keyOf(...), range(1, 14)))->toBe([
        'goal', 'about_you', 'screening', 'glp1', 'body_fat', 'rate_length', 'experience', 'training_days',
        'training_plan', 'steps', 'meals', 'weigh_ins', 'calories', 'review',
    ])
        ->and(array_map(OnboardingSteps::titleOf(...), range(1, 14)))->toBe([
            "What's your goal?", 'About you', 'Health check', 'Weight-loss drugs', 'Body fat', 'Rate and length',
            'Training experience', 'Training days', 'Training plan', 'Daily steps', 'Meals', 'Weigh-ins', 'Calories',
            'Review your plan',
        ])
        ->and(onboardingSteps(F::atStep6())->key())->toBe('rate_length')
        ->and(onboardingSteps(F::atStep6())->title())->toBe('Rate and length');
});

// ONB-018 the review

it('ONB-018 step 13 leads to the review, which is not counted in the steps', function () {
    $review = onboardingSteps(F::atReview());

    expect(onboardingSteps(F::atStep13())->next())->toEqual(new StepMove(14))
        ->and($review->position()->counter())->toBe('Review')
        ->and($review->position()->current)->toBe(13)
        ->and($review->position()->total)->toBe(13)
        ->and($review->canGoBack())->toBeTrue()
        ->and($review->back())->toBe(13)
        ->and($review->canContinue())->toBeTrue()
        ->and($review->continueHint())->toBeNull()
        ->and($review->next())->toBeNull();
});

it('ONB-018 an Edit link goes back to its step; a step that is not shown, the review or no step at all goes nowhere', function () {
    expect(onboardingSteps(F::atReview())->edit(10))->toBe(10)
        ->and(onboardingSteps(F::atReview())->edit(1))->toBe(1)
        ->and(onboardingSteps(F::atTrainingOnlyReview())->edit(10))->toBeNull()
        ->and(onboardingSteps(F::atTrainingOnlyReview())->edit(8))->toBe(8)
        ->and(onboardingSteps(F::atReview())->edit(14))->toBeNull()
        ->and(onboardingSteps(F::atReview())->edit(99))->toBeNull();
});

it('ONB-018 after an Edit, Next walks forward through the later steps back to the review', function () {
    $edited = F::atReview()->with(step: 10);

    expect(onboardingSteps($edited)->next())->toEqual(new StepMove(11))
        ->and(onboardingSteps($edited->with(step: 11))->next())->toEqual(new StepMove(12))
        ->and(onboardingSteps($edited->with(step: 12))->next())->toEqual(new StepMove(13))
        ->and(onboardingSteps($edited->with(step: 13))->next())->toEqual(new StepMove(14));
});

// can_continue and continue_hint, one step at a time

it('ONB-003 step 1 needs a goal and the recent-diet answer', function () {
    expectOnboardingContinue(new Draft, false, 'Pick a goal and answer the question to continue.');
    expectOnboardingContinue(new Draft(goal: PhaseType::MuscleGain), false, 'Pick a goal and answer the question to continue.');
    expectOnboardingContinue(new Draft(goal: PhaseType::MuscleGain, finishedDietRecently: false), true, null);
});

it('ONB-021 Fat loss after a recent diet can still continue: the advice never blocks', function () {
    expectOnboardingContinue(F::atStep2(PhaseType::FatLoss, recentDiet: true)->with(step: 1), true, null);
});

it('ONB-004 step 2 needs every field, sizes above zero and a date of birth in the past', function () {
    $full = F::atStep3()->with(step: 2);

    expectOnboardingContinue(F::atStep2(), false, 'Fill in every field to continue.');
    expectOnboardingContinue($full->with(name: ' '), false, 'Fill in every field to continue.');
    expectOnboardingContinue($full->with(birthDate: F::date(2026, 10, 10)), false, 'Fill in every field to continue.');
    expectOnboardingContinue($full, true, null);
});

it('ONB-005 step 3 can always continue', function () {
    expectOnboardingContinue(F::atStep3(), true, null);
    expectOnboardingContinue(F::atStep3()->with(screening: [ScreeningItem::Diabetes]), true, null);
});

it('ONB-006 step 4 needs the weight-loss drug answer', function () {
    $draft = F::atStep3()->with(step: 4);

    expectOnboardingContinue($draft, false, 'Answer the question to continue.');
    expectOnboardingContinue($draft->with(usesGLP1: true), true, null);
    expectOnboardingContinue($draft->with(usesGLP1: false), true, null);
});

it('ONB-007 step 5 needs a body-fat photo', function () {
    $draft = F::atStep3()->with(step: 5, usesGLP1: false);

    expectOnboardingContinue($draft, false, 'Pick a photo to continue.');
    expectOnboardingContinue($draft->with(bodyFatBucket: BodyFatBucket::High), true, null);
});

it('ONB-008 step 6 needs a rate, a start date and a length', function () {
    expectOnboardingContinue(F::atStep6()->with(ratePercentPerWeek: null), false, 'Choose a rate and a length to continue.');
    expectOnboardingContinue(F::atStep6()->with(startDate: null), false, 'Choose a rate and a length to continue.');
    expectOnboardingContinue(F::atStep6()->with(weeks: null), false, 'Choose a rate and a length to continue.');
    expectOnboardingContinue(F::atStep6(), true, null);
});

it('ONB-009 the step 6 warnings never block continuing', function () {
    expectOnboardingContinue(F::atStep6()->with(ratePercentPerWeek: -1.0, customRate: true, weeks: 16), true, null);
});

it('ONB-010 step 7 needs the training experience', function () {
    expectOnboardingContinue(F::atStep7(), false, 'Pick your training experience to continue.');
    expectOnboardingContinue(F::atStep7()->with(experience: ExperienceLevel::Advanced), true, null);
});

it('ONB-011 step 8 needs at least one training day', function () {
    expectOnboardingContinue(F::atStep8(), false, 'Pick at least one training day to continue.');
    expectOnboardingContinue(F::atStep8()->with(trainingDays: F::trainingDays([2])), true, null);
});

it('ONB-012 step 9 needs a training plan: a pick, or a recommended template to stand in', function () {
    expectOnboardingContinue(F::atStep9(), false, 'Pick a training plan to continue.', []);
    expectOnboardingContinue(F::atStep9()->with(trainingPlan: TrainingPlanKind::Later), true, null, []);
    expectOnboardingContinue(F::atStep9()->with(trainingPlan: TrainingPlanKind::MesoBuilder), true, null, []);
    expectOnboardingContinue(F::atStep9(), true, null);
    expectOnboardingContinue(F::atStep9()->with(trainingPlan: TrainingPlanKind::Template, templateId: 99), false, 'Pick a training plan to continue.', []);
});

it('ONB-013 step 10 can always continue, a high step target included', function () {
    expectOnboardingContinue(F::atStep10(), true, null);
    expectOnboardingContinue(F::atStep10()->with(stepRanges: [new StepRangeChoice(6, 8000, 13000)]), true, null);
});

it('ONB-014 step 11 needs a window that holds the meals around the busy periods', function () {
    $hint = "The eating window can't hold that many meals. Widen it, remove a busy period or have fewer meals.";
    $short = F::atStep11()->with(eatingEndMinutes: 12 * 60, mealCount: 3);

    expectOnboardingContinue(F::atStep11(), true, null);
    expectOnboardingContinue(F::atStep11()->with(eatingEndMinutes: 9 * 60, mealCount: 6), false, $hint);
    expectOnboardingContinue($short, true, null);
    expectOnboardingContinue($short->with(busyPeriods: [new BusyPeriodChoice('b1', 'Busy', 7 * 60, 11 * 60, [1])]), false, $hint);
});

it('ONB-015 step 12 needs at least two weigh-in days', function () {
    expectOnboardingContinue(F::atStep12(), false, 'Too few weigh-ins to continue');
    expectOnboardingContinue(F::atStep12()->with(weighIns: F::weighIns([2])), false, 'Too few weigh-ins to continue');
    expectOnboardingContinue(F::atStep12()->with(weighIns: F::weighIns([2, 5])), true, null);
});

it('ONB-016 the review day never blocks step 12', function () {
    expectOnboardingContinue(F::atStep12()->with(weighIns: F::weighIns([2, 5]), reviewWeekday: 3), true, null);
});

it('ONB-017 step 13 needs calories the rules accept', function () {
    expectOnboardingContinue(F::atStep13(), true, null);
    expectOnboardingContinue(F::atStep13()->with(choosesOwnCalories: true), false, 'Enter your average daily calories to continue.');
    expectOnboardingContinue(
        F::atStep13()->with(choosesOwnCalories: true, ownDailyKcal: 600), false,
        'That is too few calories to fit your protein, fat and carbs. Choose a higher average.',
    );
    expectOnboardingContinue(F::atStep13()->with(choosesOwnCalories: true, ownDailyKcal: 1900), true, null);
});

it('ONB-002 any step can be checked, not only the current one', function () {
    $steps = onboardingSteps(F::atReview());

    expect($steps->canContinueOn(12))->toBeTrue()
        ->and(onboardingSteps(F::atReview()->with(weighIns: []))->canContinueOn(12))->toBeFalse()
        ->and(onboardingSteps(F::atReview()->with(weighIns: []))->continueHintOn(12))->toBe('Too few weigh-ins to continue');
});
