<?php

use App\Domain\Energy\EnergyRules;
use App\Domain\Energy\EnergyWorkout;
use App\Domain\Model\DayType;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Sex;
use App\Domain\Model\StepPlanDay;
use App\Domain\Weight\CurrentWeight;
use Tests\Unit\Domain\Energy\EnergyFixtures as F;

// NUT-001

it('NUT-001 male BMR is Mifflin-St Jeor', function () {
    expect(EnergyRules::bmr(F::profile(), weightKg: 119, on: F::today(), timeZone: F::utc()))
        ->toEqualWithDelta(2_121.25, F::ACC);
});

it('NUT-001 female BMR subtracts 161', function () {
    // 10*70 + 6.25*165 - 5*30 - 161 = 700 + 1031.25 - 150 - 161
    $woman = F::profile(sex: Sex::Female, birthDate: F::date(1996, 1, 1), heightCm: 165);

    expect(EnergyRules::bmr($woman, weightKg: 70, on: F::today(), timeZone: F::utc()))->toEqualWithDelta(1_420.25, F::ACC);
});

it('NUT-001 age is whole years and rolls on the birthday', function () {
    $birth = F::date(1980, 10, 11);

    expect(EnergyRules::age($birth, on: F::date(2026, 10, 10), timeZone: F::utc()))->toBe(45)
        ->and(EnergyRules::age($birth, on: F::date(2026, 10, 11), timeZone: F::utc()))->toBe(46);
});

it('NUT-001 age rolls on the user\'s local calendar day, not the UTC one', function () {
    // A stored birth date is a calendar date at midnight UTC.
    $birth = F::date(1980, 10, 11);
    $newYork = new DateTimeZone('America/New_York');
    $auckland = new DateTimeZone('Pacific/Auckland');

    // 2026-10-11 02:00 UTC is still 10 October in New York; 04:00 UTC is 11 October.
    expect(EnergyRules::age($birth, on: new DateTimeImmutable('2026-10-11 02:00 UTC'), timeZone: $newYork))->toBe(45)
        ->and(EnergyRules::age($birth, on: new DateTimeImmutable('2026-10-11 04:00 UTC'), timeZone: $newYork))->toBe(46)
        // 2026-10-10 10:00 UTC is 10 October in Auckland; 12:00 UTC is already 11 October.
        ->and(EnergyRules::age($birth, on: new DateTimeImmutable('2026-10-10 10:00 UTC'), timeZone: $auckland))->toBe(45)
        ->and(EnergyRules::age($birth, on: new DateTimeImmutable('2026-10-10 12:00 UTC'), timeZone: $auckland))->toBe(46);
});

it('NUT-001 a birth date keeps its own calendar day whatever its offset', function () {
    // 11 October in Auckland is 10 October 11:00 UTC; the birthday is still 11 October.
    $birth = new DateTimeImmutable('1980-10-11 00:00', new DateTimeZone('Pacific/Auckland'));

    expect(EnergyRules::age($birth, on: F::date(2026, 10, 10), timeZone: F::utc()))->toBe(45)
        ->and(EnergyRules::age($birth, on: F::date(2026, 10, 11), timeZone: F::utc()))->toBe(46);
});

it('NUT-001 a 29 February birthday turns over on 1 March in a common year', function () {
    $birth = F::date(2000, 2, 29);

    expect(EnergyRules::age($birth, on: F::date(2025, 2, 28), timeZone: F::utc()))->toBe(24)
        ->and(EnergyRules::age($birth, on: F::date(2025, 3, 1), timeZone: F::utc()))->toBe(25)
        ->and(EnergyRules::age($birth, on: F::date(2028, 2, 29), timeZone: F::utc()))->toBe(28);
});

it('NUT-001 BMR uses the local-day age', function () {
    $newYork = new DateTimeZone('America/New_York');
    $profile = F::profile(birthDate: F::date(1980, 10, 11));

    // 1190 + 1156.25 - 5*45 + 5, then one year older on the local birthday
    expect(EnergyRules::bmr($profile, weightKg: 119, on: new DateTimeImmutable('2026-10-11 02:00 UTC'), timeZone: $newYork))
        ->toEqualWithDelta(2_126.25, F::ACC)
        ->and(EnergyRules::bmr($profile, weightKg: 119, on: new DateTimeImmutable('2026-10-11 04:00 UTC'), timeZone: $newYork))
        ->toEqualWithDelta(2_121.25, F::ACC);
});

it('NUT-001 with no trend BMR falls back to the profile weight', function () {
    $profile = F::profile(weightKg: 125);
    $weight = CurrentWeight::kg(trendKg: null, profileKg: $profile->weightKg);

    // 1250 + 1156.25 - 230 + 5
    expect(EnergyRules::bmr($profile, weightKg: $weight, on: F::today(), timeZone: F::utc()))->toEqualWithDelta(2_181.25, F::ACC);
});

// TBL-030

it('TBL-030 factor by step band', function (int $steps, float $factor) {
    expect(EnergyRules::activityFactor($steps))->toBe($factor);
})->with([
    [0, 1.20],
    [4_999, 1.20],
    [5_000, 1.30],
    [7_999, 1.30],
    [8_000, 1.375],
    [9_999, 1.375],
    [10_000, 1.45],
    [12_999, 1.45],
    [13_000, 1.55],
    [40_000, 1.55],
]);

it('TBL-030 a plan day uses the integer midpoint', function () {
    expect(EnergyRules::stepMidpoint(new StepPlanDay(weekday: 1, min: 0, max: 8_000)))->toBe(4_000)
        ->and(EnergyRules::stepMidpoint(new StepPlanDay(weekday: 1, min: 0, max: 8_001)))->toBe(4_000)
        ->and(EnergyRules::activityFactorForDay(new StepPlanDay(weekday: 1, min: 0, max: 8_000)))->toBe(1.20)
        // Midpoint 5,000 lands in the next band even though the range starts below it.
        ->and(EnergyRules::activityFactorForDay(new StepPlanDay(weekday: 2, min: 2_000, max: 8_000)))->toBe(1.30)
        ->and(EnergyRules::activityFactorForDay(new StepPlanDay(weekday: 3, min: 12_000, max: 16_000)))->toBe(1.55);
});

// NUT-002

it('NUT-002 expenditure is BMR times the activity factor plus workout energy', function () {
    expect(EnergyRules::dailyExpenditure(bmr: 2_000, activityFactor: 1.3, workoutKcal: 250))->toEqualWithDelta(2_850, F::ACC)
        ->and(EnergyRules::dailyExpenditure(bmr: 2_000, activityFactor: 1.2, workoutKcal: 0))->toEqualWithDelta(2_400, F::ACC);
});

// NUT-003

it('NUT-003 workout energy is hours times kg times the intensity factor', function () {
    expect(EnergyRules::workoutFactor(DayType::Light))->toEqual(3)
        ->and(EnergyRules::workoutFactor(DayType::Moderate))->toEqual(5)
        ->and(EnergyRules::workoutFactor(DayType::Hard))->toEqual(6)
        ->and(EnergyRules::workoutFactor(DayType::Rest))->toEqual(0)
        ->and(EnergyRules::workoutFactor(DayType::Deload))->toEqual(0)
        ->and(EnergyRules::workoutEnergy(minutes: 30, weightKg: 119, intensity: DayType::Light))->toEqualWithDelta(178.5, F::ACC)
        ->and(EnergyRules::workoutEnergy(minutes: 60, weightKg: 80, intensity: DayType::Moderate))->toEqualWithDelta(400, F::ACC)
        ->and(EnergyRules::workoutEnergy(minutes: 90, weightKg: 80, intensity: DayType::Hard))->toEqualWithDelta(720, F::ACC);
});

// NUT-004 / TBL-031

it('TBL-031 intensity comes from working sets', function () {
    expect(EnergyRules::sessionIntensity(workingSets: 9, minutes: 30, isDeloadWeek: false))->toBe(DayType::Light)
        ->and(EnergyRules::sessionIntensity(workingSets: 10, minutes: 40, isDeloadWeek: false))->toBe(DayType::Moderate)
        ->and(EnergyRules::sessionIntensity(workingSets: 19, minutes: 60, isDeloadWeek: false))->toBe(DayType::Moderate)
        ->and(EnergyRules::sessionIntensity(workingSets: 20, minutes: 60, isDeloadWeek: false))->toBe(DayType::Hard);
});

it('TBL-031 over seventy-five minutes is hard', function () {
    expect(EnergyRules::sessionIntensity(workingSets: 8, minutes: 76, isDeloadWeek: false))->toBe(DayType::Hard)
        ->and(EnergyRules::sessionIntensity(workingSets: 12, minutes: 75, isDeloadWeek: false))->toBe(DayType::Moderate);
});

it('TBL-031 a deload week is always light', function () {
    expect(EnergyRules::sessionIntensity(workingSets: 25, minutes: 90, isDeloadWeek: true))->toBe(DayType::Light);
});

it('NUT-004 intensity is never the deload case', function () {
    foreach (range(0, 30) as $sets) {
        foreach ([30.0, 120.0] as $minutes) {
            foreach ([false, true] as $deload) {
                expect(EnergyRules::sessionIntensity(workingSets: $sets, minutes: $minutes, isDeloadWeek: $deload))
                    ->not->toBe(DayType::Deload);
            }
        }
    }
});

// NUT-005

it('NUT-005 an other activity uses the picked intensity', function () {
    $run = new EnergyWorkout(minutes: 45, intensity: DayType::Hard);
    $day = EnergyRules::dayWorkout([$run], weightKg: 80);

    expect($day->kcal)->toEqualWithDelta(0.75 * 80 * 6, F::ACC)
        ->and($day->dayType)->toBe(DayType::Hard);
});

// NUT-006

it('NUT-006 the measured estimate replaces the formula', function () {
    expect(EnergyRules::expenditure(formula: 2_700, measured: 2_900))->toEqual(2_900)
        ->and(EnergyRules::expenditure(formula: 2_700, measured: null))->toEqual(2_700);
});

// NUT-007

it('NUT-007 fat loss subtracts the weekly rate energy', function () {
    $phase = F::phase(PhaseType::FatLoss, -0.5);

    expect(EnergyRules::dailyTarget(expenditure: 2_724, phase: $phase, weightKg: 119))->toEqualWithDelta(2_069.5, F::ACC);
});

it('NUT-007 muscle gain adds energy', function () {
    $phase = F::phase(PhaseType::MuscleGain, 0.25);

    // 80 * 0.0025 * 7700 / 7 = 220
    expect(EnergyRules::dailyTarget(expenditure: 3_000, phase: $phase, weightKg: 80))->toEqualWithDelta(3_220, F::ACC);
});

it('NUT-007 maintenance is the expenditure', function () {
    $phase = F::phase(PhaseType::Maintenance, 0);

    expect(EnergyRules::dailyTarget(expenditure: 2_500, phase: $phase, weightKg: 80))->toEqualWithDelta(2_500, F::ACC);
});

it('NUT-007 the loss rate is clamped at one percent', function () {
    $phase = F::phase(PhaseType::FatLoss, -2);

    // clamped to -1%: 100 * 0.01 * 7700 / 7 = 1100
    expect(EnergyRules::dailyTarget(expenditure: 3_000, phase: $phase, weightKg: 100))->toEqualWithDelta(1_900, F::ACC);
});

// SCH-046

it('SCH-046 the estimate is sets times 2.5 plus 10, rounded to five', function () {
    expect(EnergyRules::sessionMinutes(workingSets: 8))->toBe(30)
        ->and(EnergyRules::sessionMinutes(workingSets: 9))->toBe(35) // 32.5 ties up
        ->and(EnergyRules::sessionMinutes(workingSets: 10))->toBe(35) // 35
        ->and(EnergyRules::sessionMinutes(workingSets: 11))->toBe(40) // 37.5 ties up
        ->and(EnergyRules::sessionMinutes(workingSets: 12))->toBe(40) // 40
        ->and(EnergyRules::sessionMinutes(workingSets: 0))->toBe(10);
});

it('SCH-046 fewer than three actuals use the estimate', function () {
    expect(EnergyRules::sessionMinutes(workingSets: 8, pastActualMinutes: []))->toBe(30)
        ->and(EnergyRules::sessionMinutes(workingSets: 8, pastActualMinutes: [60, 70]))->toBe(30);
});

it('SCH-046 three actuals use their average rounded to five', function () {
    // (50 + 52 + 53) / 3 = 51.67 -> 50
    expect(EnergyRules::sessionMinutes(workingSets: 8, pastActualMinutes: [50, 52, 53]))->toBe(50)
        // (52 + 54 + 54) / 3 = 53.33 -> 55
        ->and(EnergyRules::sessionMinutes(workingSets: 8, pastActualMinutes: [52, 54, 54]))->toBe(55)
        // mean 52.5 -> 10.5 fives -> ties up to 55
        ->and(EnergyRules::sessionMinutes(workingSets: 8, pastActualMinutes: [50, 52.5, 55]))->toBe(55);
});

it('SCH-046 only the last three actuals count', function () {
    // oldest first: the 100 is ignored; mean of 40, 40, 40.
    expect(EnergyRules::sessionMinutes(workingSets: 8, pastActualMinutes: [100, 40, 40, 40]))->toBe(40);
});

// SCH-038

it('SCH-038 no workout is a rest day', function () {
    $day = EnergyRules::dayWorkout([], weightKg: 80);

    expect($day->kcal)->toEqual(0)
        ->and($day->dayType)->toBe(DayType::Rest);
});

it('SCH-038 energies add and the day type is the higher', function () {
    $session = new EnergyWorkout(minutes: 60, intensity: DayType::Moderate);
    $other = new EnergyWorkout(minutes: 30, intensity: DayType::Hard);
    $day = EnergyRules::dayWorkout([$session, $other], weightKg: 80);

    expect($day->kcal)->toEqualWithDelta(1 * 80 * 5 + 0.5 * 80 * 6, F::ACC)
        ->and($day->dayType)->toBe(DayType::Hard)
        ->and(EnergyRules::dayWorkout([$other, $session], weightKg: 80)->dayType)->toBe(DayType::Hard);
});

it('SCH-038 a deload session plus a moderate other activity is moderate', function () {
    $intensity = EnergyRules::sessionIntensity(workingSets: 20, minutes: 60, isDeloadWeek: true);
    expect($intensity)->toBe(DayType::Light);

    $session = new EnergyWorkout(minutes: 60, intensity: $intensity);
    $other = new EnergyWorkout(minutes: 30, intensity: DayType::Moderate);
    $day = EnergyRules::dayWorkout([$session, $other], weightKg: 100);

    expect($day->dayType)->toBe(DayType::Moderate)
        ->and($day->kcal)->toEqualWithDelta(1 * 100 * 3 + 0.5 * 100 * 5, F::ACC);
});

it('SCH-038 a deload session alone is light', function () {
    $intensity = EnergyRules::sessionIntensity(workingSets: 20, minutes: 45, isDeloadWeek: true);
    $session = new EnergyWorkout(minutes: 45, intensity: $intensity);
    $day = EnergyRules::dayWorkout([$session], weightKg: 80);

    expect($day->dayType)->toBe(DayType::Light)
        ->and($day->kcal)->toEqualWithDelta(0.75 * 80 * 3, F::ACC);
});

it('SCH-038 a rest or deload entry adds no energy and never outranks a workout', function () {
    $rest = new EnergyWorkout(minutes: 60, intensity: DayType::Rest);
    $deload = new EnergyWorkout(minutes: 60, intensity: DayType::Deload);
    $light = new EnergyWorkout(minutes: 30, intensity: DayType::Light);

    expect(EnergyRules::dayWorkout([$rest, $deload], weightKg: 80)->kcal)->toEqual(0)
        ->and(EnergyRules::dayWorkout([$rest, $deload], weightKg: 80)->dayType)->toBe(DayType::Rest)
        ->and(EnergyRules::dayWorkout([$deload, $light], weightKg: 80)->dayType)->toBe(DayType::Light)
        ->and(EnergyRules::dayWorkout([$light, $deload], weightKg: 80)->dayType)->toBe(DayType::Light);
});

// Worked example

it('NUT-001 to NUT-007 worked example: male, age 46, trend weight 119', function () {
    $profile = F::profile(birthDate: F::date(1980, 1, 1), heightCm: 185, weightKg: 125);
    $weight = CurrentWeight::kg(trendKg: 119, profileKg: $profile->weightKg);
    $bmr = EnergyRules::bmr($profile, weightKg: $weight, on: F::today(), timeZone: F::utc());
    expect($bmr)->toEqualWithDelta(2_121.25, F::ACC);

    $factor = EnergyRules::activityFactorForDay(new StepPlanDay(weekday: 1, min: 0, max: 8_000));
    expect($bmr * $factor)->toEqualWithDelta(2_545.5, F::ACC);

    $minutes = EnergyRules::sessionMinutes(workingSets: 8);
    expect($minutes)->toBe(30);
    $intensity = EnergyRules::sessionIntensity(workingSets: 8, minutes: $minutes, isDeloadWeek: false);
    expect($intensity)->toBe(DayType::Light);
    $workout = EnergyRules::workoutEnergy(minutes: $minutes, weightKg: $weight, intensity: $intensity);
    expect($workout)->toEqualWithDelta(178.5, F::ACC);

    $expenditure = EnergyRules::dailyExpenditure(bmr: $bmr, activityFactor: $factor, workoutKcal: $workout);
    expect($expenditure)->toEqualWithDelta(2_724, F::ACC)
        ->and(EnergyRules::expenditure(formula: $expenditure, measured: null))->toEqualWithDelta(2_724, F::ACC);

    $phase = F::phase(PhaseType::FatLoss, -0.5);
    expect(EnergyRules::dailyTarget(expenditure: $expenditure, phase: $phase, weightKg: $weight))->toEqualWithDelta(2_069.5, F::ACC);
});
