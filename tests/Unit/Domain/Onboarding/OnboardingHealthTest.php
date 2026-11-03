<?php

use App\Domain\Defaults\TBL001BodyFatBuckets\Row;
use App\Domain\Model\BodyFatBucket;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Sex;
use App\Domain\Onboarding\Draft;
use App\Domain\Onboarding\GoalOption;
use App\Domain\Onboarding\OnboardingHealth;
use App\Domain\Onboarding\ScreeningRow;
use App\Domain\Safety\EligibilityMode;
use App\Domain\Safety\ScreeningItem;
use Tests\Unit\Domain\Onboarding\OnboardingFixtures as F;

// Steps 1 to 5 as the read shows them (rp-clone OnboardingModel and OnboardingModel+Health, OnboardingModelTests):
// the goal options, the notices, about you, the screening list and its eligibility, GLP-1 and body fat.

function onboardingHealth(Draft $draft): OnboardingHealth
{
    return new OnboardingHealth($draft, F::today());
}

// ONB-003 goal

it('ONB-003 offers Fat loss, Muscle gain and Maintenance in that order, all enabled', function () {
    $options = onboardingHealth(new Draft)->goalOptions();

    expect(array_map(fn (GoalOption $o) => $o->title, $options))->toBe(['Fat loss', 'Muscle gain', 'Maintenance'])
        ->and(array_map(fn (GoalOption $o) => $o->goal, $options))->toBe([PhaseType::FatLoss, PhaseType::MuscleGain, PhaseType::Maintenance])
        ->and(array_map(fn (GoalOption $o) => $o->isEnabled, $options))->toBe([true, true, true])
        ->and(array_map(fn (GoalOption $o) => $o->detail, $options))->toBe([null, null, null])
        ->and(onboardingHealth(new Draft)->allowedGoals())->toBe([PhaseType::FatLoss, PhaseType::MuscleGain, PhaseType::Maintenance]);
});

it('ONB-003 help text sends fat loss and muscle gain at once to Fat loss', function () {
    expect(OnboardingHealth::GOAL_HELP)
        ->toBe('Want to lose fat and build muscle at the same time? Pick Fat loss. There is no separate recomp goal.');
});

it('ONB-003 Mini-cut is not a goal on step 1', function () {
    expect(onboardingHealth(new Draft)->isGoalAllowed(PhaseType::MiniCut))->toBeFalse();
});

// ONB-021 recent diet

it('ONB-021 asks about a recent diet on step 1', function () {
    expect(OnboardingHealth::RECENT_DIET_QUESTION)->toBe('Have you finished a fat-loss diet recently?')
        ->and(OnboardingHealth::RECENT_DIET_DETAIL)->toBe('In the past 8 weeks');
});

it('ONB-021 advises Maintenance first after a recent diet when Fat loss is picked', function () {
    $advice = onboardingHealth(F::atStep2(PhaseType::FatLoss, recentDiet: true))->maintenanceFirstAdvice();

    expect($advice)->not->toBeNull()
        ->and($advice->text)->toContain('Maintenance first')
        ->and($advice->text)->toContain('hunger, energy and training recover')
        ->and($advice->actionTitle)->toBe('Start with Maintenance');
});

it('ONB-021 gives no advice for other goals, without a recent diet, or before the question is answered', function () {
    expect(onboardingHealth(F::atStep2(PhaseType::FatLoss, recentDiet: false))->maintenanceFirstAdvice())->toBeNull()
        ->and(onboardingHealth(F::atStep2(PhaseType::MuscleGain, recentDiet: true))->maintenanceFirstAdvice())->toBeNull()
        ->and(onboardingHealth(F::atStep2(PhaseType::Maintenance, recentDiet: true))->maintenanceFirstAdvice())->toBeNull()
        ->and(onboardingHealth(new Draft(goal: PhaseType::FatLoss))->maintenanceFirstAdvice())->toBeNull();
});

// ONB-004 about you

it('ONB-004 about you needs a name, sex, date of birth, height and weight', function () {
    $full = F::atStep3()->with(step: 2);

    expect(onboardingHealth($full)->hasAboutYou())->toBeTrue()
        ->and(onboardingHealth($full->with(name: '   '))->hasAboutYou())->toBeFalse()
        ->and(onboardingHealth($full->with(sex: null))->hasAboutYou())->toBeFalse()
        ->and(onboardingHealth($full->with(birthDate: null))->hasAboutYou())->toBeFalse()
        ->and(onboardingHealth($full->with(heightCm: 0.0))->hasAboutYou())->toBeFalse()
        ->and(onboardingHealth($full->with(weightKg: 0.0))->hasAboutYou())->toBeFalse()
        ->and(onboardingHealth($full->with(weightKg: null))->hasAboutYou())->toBeFalse();
});

it('ONB-004 the date of birth must be before today', function () {
    $full = F::atStep3()->with(step: 2);

    expect(onboardingHealth($full->with(birthDate: F::tomorrow()))->hasAboutYou())->toBeFalse()
        ->and(onboardingHealth($full->with(birthDate: F::date(2026, 10, 10)))->hasAboutYou())->toBeFalse()
        ->and(onboardingHealth($full->with(birthDate: F::date(2026, 10, 9)))->hasAboutYou())->toBeTrue();
});

it('ONB-004 the date of birth picker starts 30 years ago', function () {
    expect(onboardingHealth(new Draft)->defaultBirthDate())->toEqual(F::date(1996, 10, 10));
});

// SAFE-001, SAFE-002, SAFE-014 screening

it('SAFE-001 lists all thirteen items in the spec order with their labels', function () {
    $rows = onboardingHealth(F::atStep3())->screeningRows();

    expect(array_map(fn (ScreeningRow $row) => $row->item, $rows))->toBe(ScreeningItem::cases())
        ->and($rows[0]->label)->toBe('Under 18 or over 65')
        ->and($rows[1]->label)->toBe('Height under 54 in (137 cm) or over 84 in (213 cm)')
        ->and($rows[2]->label)->toBe('Weight over 350 lb (159 kg)')
        ->and($rows[3]->label)->toBe('BMI under 17')
        ->and($rows[5]->label)->toBe('Trying to conceive or in fertility treatment')
        ->and($rows[12]->label)->toBe('A history of eating disorders')
        ->and(count(array_unique(array_map(fn (ScreeningRow $row) => $row->label, $rows))))->toBe(13)
        ->and(OnboardingHealth::SCREENING_PROMPT)->toBe('Tick anything that applies to you.');
});

it('SAFE-002 the screening step tells the user to get medical clearance', function () {
    expect(OnboardingHealth::MEDICAL_CLEARANCE_NOTE)->toBe('Get medical clearance from your doctor before starting any diet.');
});

it('SAFE-014 the screening step says medical diets are not supported', function () {
    expect(OnboardingHealth::MEDICAL_DIETS_NOTE)
        ->toBe('This app does not support medical diets. If you have a metabolic condition, consult a physician first.');
});

it('ONB-005 a ticked item shows on; "trying to conceive" says only Fat loss is off', function () {
    $rows = onboardingHealth(F::atStep3()->with(screening: [ScreeningItem::PregnantOrBreastfeeding]))->screeningRows();
    $byItem = array_column(array_map(fn (ScreeningRow $row) => [$row->item->value, $row], $rows), 1, 0);

    expect($byItem['pregnantOrBreastfeeding']->isOn)->toBeTrue()
        ->and($byItem['pregnantOrBreastfeeding']->isLocked)->toBeFalse()
        ->and($byItem['diabetes']->isOn)->toBeFalse()
        ->and($byItem['diabetes']->detail)->toBeNull()
        ->and($byItem['tryingToConceive']->detail)->toBe('Only Fat loss is unavailable.');
});

it('ONB-005 an item measured on step 2 shows ticked and locked, from the answers on step 2', function () {
    $rows = onboardingHealth(F::atStep3(birthDate: F::minorBirthDate()))->screeningRows();

    expect($rows[0]->item)->toBe(ScreeningItem::AgeOutOfRange)
        ->and($rows[0]->isOn)->toBeTrue()
        ->and($rows[0]->isLocked)->toBeTrue()
        ->and($rows[0]->detail)->toBe('From your answers on step 2');
});

it('ONB-005 the measured items are locked even when they do not apply: only step 2 changes them', function () {
    $rows = onboardingHealth(F::atStep3())->screeningRows();

    expect(array_map(fn (ScreeningRow $row) => $row->isLocked, array_slice($rows, 0, 5)))->toBe([true, true, true, true, false])
        ->and(array_map(fn (ScreeningRow $row) => $row->isOn, array_slice($rows, 0, 4)))->toBe([false, false, false, false])
        ->and($rows[0]->detail)->toBeNull();
});

it('ONB-005 with step 2 unanswered nothing is measured', function () {
    expect(onboardingHealth(new Draft(birthDate: F::minorBirthDate()))->measuredItems())->toBe([])
        ->and(onboardingHealth(F::atStep3(birthDate: F::minorBirthDate()))->measuredItems())->toBe([ScreeningItem::AgeOutOfRange]);
});

it('ONB-005 eligibility counts the ticked and the measured items', function () {
    expect(onboardingHealth(F::atStep3())->eligibility()->mode)->toBe(EligibilityMode::AllTargets)
        ->and(onboardingHealth(F::atStep3()->with(screening: [ScreeningItem::TryingToConceive]))->eligibility()->mode)->toBe(EligibilityMode::FatLossBlocked)
        ->and(onboardingHealth(F::atStep3()->with(screening: [ScreeningItem::Diabetes]))->isTrainingOnly())->toBeTrue()
        ->and(onboardingHealth(F::atStep3(birthDate: F::minorBirthDate()))->isTrainingOnly())->toBeTrue()
        ->and(onboardingHealth(F::atStep3()->with(screening: [ScreeningItem::TryingToConceive, ScreeningItem::Diabetes]))->isTrainingOnly())->toBeTrue();
});

it('ONB-005 Fat loss is disabled while trying to conceive, with the reason', function () {
    $draft = F::atStep3()->with(step: 1, goal: null, screening: [ScreeningItem::TryingToConceive]);
    $fatLoss = onboardingHealth($draft)->goalOptions()[0];

    expect(onboardingHealth($draft)->isGoalAllowed(PhaseType::FatLoss))->toBeFalse()
        ->and(onboardingHealth($draft)->isGoalAllowed(PhaseType::MuscleGain))->toBeTrue()
        ->and(onboardingHealth($draft)->allowedGoals())->toBe([PhaseType::MuscleGain, PhaseType::Maintenance])
        ->and($fatLoss->isEnabled)->toBeFalse()
        ->and($fatLoss->detail)->toBe("Fat loss isn't available while you're trying to conceive or in fertility treatment.");
});

it('ONB-005 step 1 says why Fat loss is off until another goal is picked', function () {
    $blocked = F::atStep3()->with(step: 1, goal: null, screening: [ScreeningItem::TryingToConceive]);

    expect(onboardingHealth($blocked)->notice())
        ->toBe("Fat loss isn't available while you're trying to conceive or in fertility treatment. Pick Maintenance or Muscle gain.")
        ->and(onboardingHealth($blocked->with(goal: PhaseType::FatLoss))->notice())->toBe(OnboardingHealth::FAT_LOSS_BLOCKED_NOTICE)
        ->and(onboardingHealth($blocked->with(goal: PhaseType::MuscleGain))->notice())->toBeNull()
        ->and(onboardingHealth(F::atStep3())->notice())->toBeNull();
});

it('ONB-005 training-only keeps the goal from step 1: it still sets the training defaults', function () {
    $draft = F::atStep7TrainingOnly(PhaseType::FatLoss);

    expect(onboardingHealth($draft)->isGoalAllowed(PhaseType::FatLoss))->toBeTrue()
        ->and($draft->goal)->toBe(PhaseType::FatLoss);
});

// SAFE-003

it('SAFE-003 once screening is passed training-only, says nutrition targets are off and why', function () {
    $onStep3 = F::atStep3()->with(screening: [ScreeningItem::Diabetes]);
    $notice = onboardingHealth($onStep3->with(step: 7))->trainingOnlyNotice();

    expect(onboardingHealth($onStep3)->trainingOnlyNotice())->toBeNull()
        ->and($notice)->toStartWith('Nutrition targets are turned off. You can still use every training feature, log weigh-ins and see your trend weight.')
        ->and($notice)->toContain("\n• ".ScreeningItem::Diabetes->reason());
});

it('SAFE-003 no training-only notice while nutrition targets are on', function () {
    expect(onboardingHealth(F::atStep3()->with(step: 4))->trainingOnlyNotice())->toBeNull()
        ->and(onboardingHealth(F::atStep3()->with(step: 4, goal: PhaseType::Maintenance, screening: [ScreeningItem::TryingToConceive]))->trainingOnlyNotice())->toBeNull();
});

// ONB-006, ONB-007

it('ONB-006 asks about weight-loss drugs', function () {
    expect(OnboardingHealth::GLP1_QUESTION)->toBe('Do you take a GLP-1 or similar weight-loss drug?')
        ->and(OnboardingHealth::GLP1_DETAIL)->toBe('For example semaglutide, tirzepatide or retatrutide.');
});

it('ONB-007 the body-fat buckets follow the chosen sex, female until one is picked', function () {
    $labels = fn (Draft $draft) => array_map(fn (Row $row) => $row->label, onboardingHealth($draft)->bodyFatRows());

    expect($labels(new Draft(sex: Sex::Male)))->toBe(['under 15%', '15-22%', '22-30%', 'over 30%'])
        ->and($labels(new Draft(sex: Sex::Female)))->toBe(['under 20%', '20-27%', '27-35%', 'over 35%'])
        ->and($labels(new Draft))->toBe(['under 20%', '20-27%', '27-35%', 'over 35%'])
        ->and(array_map(fn (Row $row) => $row->bucket, onboardingHealth(new Draft)->bodyFatRows()))->toBe(BodyFatBucket::cases());
});

it('ONB-007 help text says accuracy is not critical and not to use smart scales', function () {
    expect(OnboardingHealth::BODY_FAT_HELP)
        ->toBe("Pick the photo that looks most like you. Accuracy isn't critical. Don't use body-fat readings from a smart scale.");
});
