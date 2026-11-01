<?php

use App\Domain\Model\BodyFatBucket;
use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\HeightUnit;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Profile;
use App\Domain\Model\Sex;
use App\Domain\Model\UnitConversion;
use App\Domain\Model\WeightUnit;
use App\Domain\Safety\Eligibility;
use App\Domain\Safety\EligibilityFeature;
use App\Domain\Safety\RecheckOutcome;
use App\Domain\Safety\RecheckOutcomeKind;
use App\Domain\Safety\Screening;
use App\Domain\Safety\ScreeningItem;

function screeningDate(int $y, int $m, int $d): DateTimeImmutable
{
    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $m, $d), new DateTimeZone('UTC'));
}

function screeningToday(): DateTimeImmutable
{
    return screeningDate(2026, 6, 15);
}

function screeningProfile(?DateTimeImmutable $born = null, float $heightCm = 175, float $weightKg = 80): Profile
{
    return new Profile(
        name: 'Sam', sex: Sex::Female, birthDate: $born ?? screeningDate(1990, 1, 1), heightCm: $heightCm,
        weightKg: $weightKg, weightUnit: WeightUnit::Kg, heightUnit: HeightUnit::Cm, bodyFatBucket: BodyFatBucket::Low,
        usesGLP1: false, experience: ExperienceLevel::Beginner,
    );
}

/**
 * @param  list<ScreeningItem>  $answers
 * @return list<ScreeningItem>
 */
function screeningItems(Profile $profile, array $answers = []): array
{
    return Screening::eligibility($profile, $answers, screeningToday())->reasons;
}

function assertBlocksAll(ScreeningItem $item): void
{
    expect($item->blocksAllTargets())->toBeTrue()
        ->and(Screening::eligibility(screeningProfile(), [$item], screeningToday()))
        ->toEqual(Eligibility::trainingOnly([$item]));
}

it('SAFE-001 allows every target when nothing applies', function () {
    $result = Screening::eligibility(screeningProfile(), [], screeningToday());

    expect($result)->toEqual(Eligibility::allTargets())
        ->and($result->allowedPhases())->toBe(PhaseType::cases())
        ->and($result->hasNutritionTargets())->toBeTrue()
        ->and($result->reasons)->toBe([]);
});

it('SAFE-001 blocks only Fat loss and Mini-cut for trying to conceive', function () {
    $result = Screening::eligibility(screeningProfile(), [ScreeningItem::TryingToConceive], screeningToday());

    expect($result)->toEqual(Eligibility::fatLossBlocked([ScreeningItem::TryingToConceive]))
        ->and($result->allowedPhases())->toBe([PhaseType::Maintenance, PhaseType::MuscleGain])
        ->and($result->hasNutritionTargets())->toBeTrue();
});

it('SAFE-001 blocks every target for any other item and lists every reason', function () {
    $result = Screening::eligibility(
        screeningProfile(weightKg: 170), [ScreeningItem::Diabetes, ScreeningItem::TryingToConceive], screeningToday());

    expect($result)->toEqual(Eligibility::trainingOnly(
        [ScreeningItem::WeightOverLimit, ScreeningItem::TryingToConceive, ScreeningItem::Diabetes]))
        ->and($result->allowedPhases())->toBe([])
        ->and($result->hasNutritionTargets())->toBeFalse();
});

it('SAFE-001 gives reasons in the spec order, each with its own explanation', function () {
    $every = Screening::eligibilityFor(ScreeningItem::cases());
    $reasons = array_map(fn (ScreeningItem $item) => $item->reason(), ScreeningItem::cases());

    expect($every->reasons)->toBe(ScreeningItem::cases())
        ->and(array_unique($reasons))->toHaveCount(count(ScreeningItem::cases()))
        ->and(ScreeningItem::TryingToConceive->reason())->toBe('You are trying to conceive or in fertility treatment');
});

it('SAFE-001 blocks all targets for every item alone but trying to conceive', function () {
    expect(ScreeningItem::cases())->toHaveCount(13);

    foreach (ScreeningItem::cases() as $item) {
        $result = Screening::eligibilityFor([$item]);
        $expected = $item === ScreeningItem::TryingToConceive
            ? Eligibility::fatLossBlocked([$item])
            : Eligibility::trainingOnly([$item]);

        expect($result)->toEqual($expected, $item->name)
            ->and($item->reason())->not->toBe('');
    }
});

it('SAFE-001 item: under 18 or over 65, from date of birth', function () {
    expect(screeningItems(screeningProfile(born: screeningDate(2008, 6, 16))))->toBe([ScreeningItem::AgeOutOfRange]) // 17, birthday tomorrow
        ->and(screeningItems(screeningProfile(born: screeningDate(2008, 6, 15))))->toBe([]) // 18 today
        ->and(screeningItems(screeningProfile(born: screeningDate(1961, 6, 15))))->toBe([]) // 65 today
        ->and(screeningItems(screeningProfile(born: screeningDate(1960, 6, 15))))->toBe([ScreeningItem::AgeOutOfRange]) // 66 today
        ->and(screeningItems(screeningProfile(born: screeningDate(1960, 6, 16))))->toBe([]); // still 65
});

it('SAFE-001 item: age compares calendar dates, whatever the time zone or time of day', function () {
    $at = fn (string $value) => new DateTimeImmutable($value);

    // Born 16 June at midnight in +10:00 (15 June 14:00 UTC): still 17 on 15 June.
    expect(Screening::measuredItemsFrom($at('2008-06-16T00:00:00+10:00'), 175, 80, $at('2026-06-15T15:00:00Z')))
        ->toBe([ScreeningItem::AgeOutOfRange])
        // Born late on 15 June: 18 all day on 15 June.
        ->and(Screening::measuredItemsFrom($at('2008-06-15T23:00:00Z'), 175, 80, $at('2026-06-15T10:00:00Z')))
        ->toBe([])
        // Today is 15 June at local midnight in +10:00 (14 June 14:00 UTC): 18 today.
        ->and(Screening::measuredItemsFrom(screeningDate(2008, 6, 15), 175, 80, $at('2026-06-15T00:00:00+10:00')))
        ->toBe([]);
});

it('SAFE-001 item: a zero height is out of range and does not crash the BMI check', function () {
    expect(Screening::measuredItemsFrom(screeningDate(1990, 1, 1), 0, 70, screeningToday()))
        ->toBe([ScreeningItem::HeightOutOfRange]);
});

it('SAFE-003 the measured reasons state the limits the checks use', function () {
    expect(ScreeningItem::AgeOutOfRange->reason())->toBe('You are under 18 or over 65')
        ->and(ScreeningItem::HeightOutOfRange->reason())->toBe('Your height is under 54 in (137 cm) or over 84 in (213 cm)')
        ->and(ScreeningItem::WeightOverLimit->reason())->toBe('Your weight is over 350 lb (159 kg)')
        ->and(ScreeningItem::BmiUnderLimit->reason())->toBe('Your BMI is under 17');
});

it('SAFE-001 item: height under 54 in or over 84 in', function () {
    expect(screeningItems(screeningProfile(heightCm: 54 * 2.54 - 0.01, weightKg: 40)))->toBe([ScreeningItem::HeightOutOfRange])
        ->and(screeningItems(screeningProfile(heightCm: 54 * 2.54, weightKg: 40)))->toBe([])
        ->and(screeningItems(screeningProfile(heightCm: 84 * 2.54, weightKg: 100)))->toBe([])
        ->and(screeningItems(screeningProfile(heightCm: 84 * 2.54 + 0.01, weightKg: 100)))->toBe([ScreeningItem::HeightOutOfRange]);
});

it('SAFE-001 item: weight over 350 lb', function () {
    expect(screeningItems(screeningProfile(heightCm: 200, weightKg: UnitConversion::lbToKg(350))))->toBe([])
        ->and(screeningItems(screeningProfile(heightCm: 200, weightKg: UnitConversion::lbToKg(350.1))))->toBe([ScreeningItem::WeightOverLimit]);
});

it('SAFE-001 item: BMI under 17, and exactly 17 passes', function () {
    expect(screeningItems(screeningProfile(heightCm: 200, weightKg: 68)))->toBe([]) // BMI 17.0
        ->and(screeningItems(screeningProfile(heightCm: 200, weightKg: 67.9)))->toBe([ScreeningItem::BmiUnderLimit]);
});

it('SAFE-001 item: pregnant or breastfeeding', fn () => assertBlocksAll(ScreeningItem::PregnantOrBreastfeeding));

it('SAFE-001 item: trying to conceive', function () {
    expect(screeningItems(screeningProfile(), [ScreeningItem::TryingToConceive]))->toBe([ScreeningItem::TryingToConceive])
        ->and(ScreeningItem::TryingToConceive->blocksAllTargets())->toBeFalse();
});

it('SAFE-001 item: transgender on hormone therapy', fn () => assertBlocksAll(ScreeningItem::TransgenderOnHormoneTherapy));
it('SAFE-001 item: heart disease, thyroid or PCOS', fn () => assertBlocksAll(ScreeningItem::HeartThyroidOrPCOS));
it('SAFE-001 item: weight-loss surgery', fn () => assertBlocksAll(ScreeningItem::WeightLossSurgery));
it('SAFE-001 item: gallbladder disease', fn () => assertBlocksAll(ScreeningItem::GallbladderDisease));
it('SAFE-001 item: autoimmune disease', fn () => assertBlocksAll(ScreeningItem::AutoimmuneDisease));
it('SAFE-001 item: diabetes', fn () => assertBlocksAll(ScreeningItem::Diabetes));
it('SAFE-001 item: eating disorder history', fn () => assertBlocksAll(ScreeningItem::EatingDisorderHistory));

it('SAFE-001 counts a measured item the user ticks by hand', function () {
    expect(screeningItems(screeningProfile(), [ScreeningItem::BmiUnderLimit]))->toBe([ScreeningItem::BmiUnderLimit]);
});

it('ONB-005 works out the measured items from the raw answers before a profile exists', function () {
    expect(Screening::measuredItemsFrom(screeningDate(1990, 1, 1), heightCm: 175, weightKg: 80, today: screeningToday()))->toBe([])
        ->and(Screening::measuredItemsFrom(screeningDate(1990, 1, 1), heightCm: 190, weightKg: 50, today: screeningToday()))
        ->toBe([ScreeningItem::BmiUnderLimit])
        ->and(Screening::measuredItemsFrom(screeningDate(2010, 1, 1), heightCm: 130, weightKg: 200, today: screeningToday()))
        ->toBe([ScreeningItem::AgeOutOfRange, ScreeningItem::HeightOutOfRange, ScreeningItem::WeightOverLimit]);
});

it('SAFE-003 keeps training, turns nutrition targets off and says why in training-only mode', function () {
    $result = Eligibility::trainingOnly([ScreeningItem::Diabetes]);

    expect($result->allows(EligibilityFeature::Training))->toBeTrue()
        ->and($result->allows(EligibilityFeature::NutritionTargets))->toBeFalse()
        ->and(array_map(fn (ScreeningItem $item) => $item->reason(), $result->reasons))->toBe([ScreeningItem::Diabetes->reason()])
        ->and(Eligibility::fatLossBlocked([ScreeningItem::TryingToConceive])->allows(EligibilityFeature::NutritionTargets))->toBeTrue()
        ->and(Eligibility::allTargets()->allows(EligibilityFeature::NutritionTargets))->toBeTrue();
});

it('SAFE-004 keeps weigh-ins and trend weight in training-only mode', function () {
    $result = Eligibility::trainingOnly([ScreeningItem::AgeOutOfRange]);

    expect($result->allows(EligibilityFeature::WeighIns))->toBeTrue()
        ->and($result->allows(EligibilityFeature::TrendWeight))->toBeTrue();
});

it('SAFE-017 runs the skipped nutrition steps, from the formula, for a training-only user who now passes', function () {
    $outcome = Screening::recheck(
        previous: Eligibility::trainingOnly([ScreeningItem::PregnantOrBreastfeeding]), now: Eligibility::allTargets(),
        currentPhase: PhaseType::FatLoss);

    expect($outcome)->toEqual(new RecheckOutcome(RecheckOutcomeKind::RunNutritionSetup, Eligibility::allTargets()))
        ->and(Screening::NUTRITION_SETUP_STEPS)->toBe([4, 5, 6, 10, 11, 13])
        ->and($outcome->startsFromFormula())->toBeTrue();

    $conceive = Eligibility::fatLossBlocked([ScreeningItem::TryingToConceive]);
    expect(Screening::recheck(
        previous: Eligibility::trainingOnly([ScreeningItem::Diabetes]), now: $conceive, currentPhase: PhaseType::Maintenance))
        ->toEqual(new RecheckOutcome(RecheckOutcomeKind::RunNutritionSetup, $conceive));
});

it('SAFE-017 moves a nutrition user who now fails to training-only at once, with the reason', function () {
    $now = Eligibility::trainingOnly([ScreeningItem::PregnantOrBreastfeeding]);

    $fromAll = Screening::recheck(previous: Eligibility::allTargets(), now: $now, currentPhase: PhaseType::Maintenance);
    $fromConceive = Screening::recheck(
        previous: Eligibility::fatLossBlocked([ScreeningItem::TryingToConceive]), now: $now, currentPhase: PhaseType::MuscleGain);

    foreach ([$fromAll, $fromConceive] as $outcome) {
        expect($outcome->kind)->toBe(RecheckOutcomeKind::SwitchToTrainingOnly)
            ->and($outcome->reasons())->toBe([ScreeningItem::PregnantOrBreastfeeding]);
    }
});

it('SAFE-017 makes a user in Fat loss or Mini-cut who is now trying to conceive leave the phase', function () {
    $conceive = Eligibility::fatLossBlocked([ScreeningItem::TryingToConceive]);

    foreach ([PhaseType::FatLoss, PhaseType::MiniCut] as $phase) {
        $outcome = Screening::recheck(previous: Eligibility::allTargets(), now: $conceive, currentPhase: $phase);

        expect($outcome->kind)->toBe(RecheckOutcomeKind::LeaveFatLoss, $phase->name)
            ->and($outcome->reasons())->toBe([ScreeningItem::TryingToConceive]);
    }
});

it('SAFE-017 keeps the mode and stores the new result otherwise', function () {
    $conceive = Eligibility::fatLossBlocked([ScreeningItem::TryingToConceive]);
    $all = Eligibility::allTargets();
    $still = Eligibility::trainingOnly([ScreeningItem::Diabetes]);
    $keep = fn (Eligibility $now) => new RecheckOutcome(RecheckOutcomeKind::Keep, $now);

    expect(Screening::recheck(previous: $all, now: $all, currentPhase: PhaseType::FatLoss))->toEqual($keep($all))
        ->and(Screening::recheck(previous: $all, now: $conceive, currentPhase: PhaseType::Maintenance))->toEqual($keep($conceive))
        ->and(Screening::recheck(previous: $conceive, now: $all, currentPhase: PhaseType::MuscleGain))->toEqual($keep($all))
        ->and(Screening::recheck(
            previous: Eligibility::trainingOnly([ScreeningItem::AgeOutOfRange]), now: $still, currentPhase: PhaseType::FatLoss))
        ->toEqual($keep($still))
        ->and($keep($all)->startsFromFormula())->toBeFalse();
});
