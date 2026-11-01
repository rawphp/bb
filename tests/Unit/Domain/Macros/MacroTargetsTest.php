<?php

use App\Domain\Macros\CarbBoundsWarning;
use App\Domain\Macros\DayTarget;
use App\Domain\Macros\PlannedDay;
use App\Domain\Macros\TargetRecommendation;
use App\Domain\Macros\TargetRefusal;
use App\Domain\Macros\TargetRefusalReason;
use App\Domain\Model\BodyFatBucket;
use App\Domain\Model\DayType;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Sex;
use Tests\Unit\Domain\Macros\MacroFixtures as F;

// NUT-010 protein

it('NUT-010 protein is 2.2 per kg at or below threshold', function () {
    $week = F::base();
    foreach ($week->days as $day) {
        expect($day->proteinG)->toEqualWithDelta(198, F::ACC); // 2.2 x 90
    }
    expect($week->leanMassKg)->toEqualWithDelta(73.35, F::ACC); // 90 x (1 - 0.185)
});

it('NUT-010 male switches to lean adjusted above twenty percent', function () {
    expect(F::targets(bodyFatPercent: 20)->days[0]->proteinG)->toEqualWithDelta(198, F::ACC)
        // 90 x 0.795 = 71.55 lean; x 1.25 = 89.4375; x 2.2 = 196.7625
        ->and(F::targets(bodyFatPercent: 20.5)->days[0]->proteinG)->toEqualWithDelta(196.7625, F::ACC)
        // high bucket = 26%: 66.6 x 1.25 x 2.2 = 183.15
        ->and(F::targets(profile: F::profile(bucket: BodyFatBucket::High))->days[0]->proteinG)->toEqualWithDelta(183.15, F::ACC);
});

it('NUT-010 female switches to lean adjusted above thirty percent', function () {
    $female = F::profile(sex: Sex::Female, heightCm: 170, weightKg: 70);
    $days = F::uniformDays(2200, DayType::Rest);

    expect(F::targets(profile: $female, days: $days, bodyFatPercent: 30)->days[0]->proteinG)->toEqualWithDelta(154, F::ACC)
        // 70 x 0.695 = 48.65 lean; x 1.25 = 60.8125; x 2.2 = 133.7875
        ->and(F::targets(profile: $female, days: $days, bodyFatPercent: 30.5)->days[0]->proteinG)->toEqualWithDelta(133.7875, F::ACC)
        // 20-27% bucket (23.5%) stays on bodyweight even though it is above the male threshold
        ->and(F::targets(profile: $female, days: $days)->days[0]->proteinG)->toEqualWithDelta(154, F::ACC);
});

// NUT-011 muscle-gain protein boost

it('NUT-011 protein boosted for first two weeks after fat loss', function () {
    $gain = F::phase(PhaseType::MuscleGain, ratePercent: 0.25);
    foreach ([1, 2] as $weekOfPhase) {
        $boosted = F::targets(phase: $gain, previous: PhaseType::FatLoss, weekOfPhase: $weekOfPhase);
        expect($boosted->days[0]->proteinG)->toEqualWithDelta(297, F::ACC); // 1.5 x 198, = 3.3 g/kg cap
    }
    $third = F::targets(phase: $gain, previous: PhaseType::FatLoss, weekOfPhase: 3);
    expect($third->days[0]->proteinG)->toEqualWithDelta(198, F::ACC);
});

it('NUT-011 mini cut counts as fat loss', function () {
    $gain = F::phase(PhaseType::MuscleGain, ratePercent: 0.25);
    expect(F::targets(phase: $gain, previous: PhaseType::MiniCut)->days[0]->proteinG)->toEqualWithDelta(297, F::ACC);
});

it('NUT-011 no boost without preceding fat loss or outside muscle gain', function () {
    $gain = F::phase(PhaseType::MuscleGain, ratePercent: 0.25);

    expect(F::targets(phase: $gain, previous: PhaseType::Maintenance)->days[0]->proteinG)->toEqualWithDelta(198, F::ACC)
        ->and(F::targets(phase: $gain, previous: null)->days[0]->proteinG)->toEqualWithDelta(198, F::ACC)
        ->and(F::targets(phase: F::phase(PhaseType::Maintenance), previous: PhaseType::FatLoss)->days[0]->proteinG)->toEqualWithDelta(198, F::ACC);
});

// Not cap coverage: 1.5 x 2.2 = 3.3 g/kg exactly, so the min cap in MacroTargets never binds with these constants.
it('NUT-011 boosted protein is 3.3 per kg', function () {
    $gain = F::phase(PhaseType::MuscleGain, ratePercent: 0.25);
    expect(F::targets(phase: $gain, previous: PhaseType::FatLoss)->days[0]->proteinG)->toBeLessThanOrEqual(3.3 * 90 + F::ACC);
});

// NUT-012 fat

it('NUT-012 fat defaults to quarter of average calories', function () {
    // 0.25 x 2800 = 700 kcal = 77.7778 g, same every day
    foreach (F::base()->days as $day) {
        expect($day->fatG)->toEqualWithDelta(700.0 / 9.0, F::ACC);
    }
});

it('NUT-012 fat floor fifteen percent of biggest day binds', function () {
    // Six days at 2000 and one at 5000: average 2428.57, 25% = 67.46 g; 15% of 5000 = 750 kcal = 83.333 g.
    $days = F::uniformDays(2000, DayType::Rest, count: 6);
    $days[] = new PlannedDay(5000, DayType::Hard);
    foreach (F::targets(days: $days)->days as $day) {
        expect($day->fatG)->toEqualWithDelta(750.0 / 9.0, F::ACC);
    }
});

it('NUT-012 fat floor per kg lean mass binds', function () {
    // Lowest bucket: 12% body fat, lean 79.2 kg, floor 0.66 x 79.2 = 52.272 g.
    // 25% of 1800 kcal = 50 g and 15% = 30 g, so the lean-mass arm wins.
    $week = F::targets(profile: F::profile(bucket: BodyFatBucket::Lowest), days: F::uniformDays(1800, DayType::Rest));

    expect($week->leanMassKg)->toEqualWithDelta(79.2, F::ACC)
        ->and($week->days[0]->fatG)->toEqualWithDelta(52.272, F::ACC);
});

// NUT-013 carbs take the remainder

it('NUT-013 carbs take remaining calories', function () {
    $week = F::base();
    // (day kcal - 4 x 198 - 700) / 4
    expect($week->days[0]->carbsG)->toEqualWithDelta((2600 - 792 - 700) / 4, F::ACC) // 277
        ->and($week->days[1]->carbsG)->toEqualWithDelta((3100 - 792 - 700) / 4, F::ACC) // 402
        ->and($week->days[4]->carbsG)->toEqualWithDelta(352, F::ACC)
        ->and($week->days[6]->carbsG)->toEqualWithDelta(302, F::ACC);
    foreach (F::mixedDays() as $index => $planned) {
        expect($week->days[$index]->kcal())->toEqualWithDelta($planned->expenditureKcal, F::ACC);
    }
});

// NUT-014 single mechanism

it('NUT-014 protein and fat identical across days carbs carry the difference', function () {
    $week = F::base();

    expect(array_unique(array_map(fn (DayTarget $day) => $day->proteinG, $week->days)))->toHaveCount(1)
        ->and(array_unique(array_map(fn (DayTarget $day) => $day->fatG, $week->days)))->toHaveCount(1)
        // hard (3100) vs rest (2600): 500 kcal / 4 = 125 g more carbs
        ->and($week->days[1]->carbsG - $week->days[0]->carbsG)->toEqualWithDelta(125, F::ACC);
});

it('NUT-014 day calories are expenditure plus phase change', function () {
    // -0.5%/wk x 90 kg x 7700 / 7 = -495 kcal per day
    $week = F::targets(phase: F::phase(PhaseType::FatLoss, ratePercent: -0.5));

    expect($week->days[0]->kcal())->toEqualWithDelta(2105, F::ACC)
        ->and($week->days[1]->kcal())->toEqualWithDelta(2605, F::ACC)
        ->and($week->days[1]->carbsG - $week->days[0]->carbsG)->toEqualWithDelta(125, F::ACC)
        // fat 25% of the 2305 average: 576.25 kcal
        ->and($week->days[0]->fatG)->toEqualWithDelta(576.25 / 9, F::ACC)
        ->and($week->days[0]->carbsG)->toEqualWithDelta((2105 - 792 - 576.25) / 4, F::ACC); // 184.1875
});

// NUT-017 / NUT-018 every macro counts

it('NUT-017 carbs are total carbs not net of fiber', function () {
    // Day calories are 4 x carbs with no fiber deduction.
    $day = new DayTarget(DayType::Rest, isCompleted: false, proteinG: 100, fatG: 50, carbsG: 200, carbBoundsWarning: null);
    expect($day->kcal())->toEqualWithDelta(4 * 100 + 9 * 50 + 4 * 200, F::ACC);
});

it('NUT-018 every macro counts toward the day', function () {
    $day = new DayTarget(DayType::Hard, isCompleted: false, proteinG: 198, fatG: 70, carbsG: 400, carbBoundsWarning: null);
    expect($day->kcal())->toEqualWithDelta(792 + 630 + 1600, F::ACC);

    $week = F::base();
    foreach (F::mixedDays() as $index => $planned) {
        $target = $week->days[$index];
        expect(4 * $target->proteinG + 4 * $target->carbsG + 9 * $target->fatG)->toEqualWithDelta($planned->expenditureKcal, F::ACC);
    }
});

// NUT-020 weekly goal

it('NUT-020 daily targets add up to the week', function () {
    $maint = F::base();
    $cut = F::targets(phase: F::phase(PhaseType::FatLoss, ratePercent: -0.5));

    expect($maint->weeklyKcal())->toEqualWithDelta(19600, F::ACC)
        ->and($maint->dailyAverageKcal())->toEqualWithDelta(2800, F::ACC)
        ->and($cut->weeklyKcal())->toEqualWithDelta(19600 - 7 * 495, F::ACC) // 16135
        ->and($cut->dailyAverageKcal())->toEqualWithDelta(2305, F::ACC)
        ->and($cut->weeklyKcal())->toEqualWithDelta(array_sum(array_map(fn (DayTarget $day) => $day->kcal(), $cut->days)), F::ACC);
});

// NUT-023 / PLAN-022 / PLAN-024 diet break and deload

it('NUT-023 diet break week runs at expenditure', function () {
    $cut = F::phase(PhaseType::FatLoss, ratePercent: -0.5);
    $week = F::targets(phase: $cut, week: F::week(dietBreak: true));

    expect($week->weeklyKcal())->toEqualWithDelta(19600, F::ACC);
    foreach (F::mixedDays() as $index => $planned) {
        expect($week->days[$index]->kcal())->toEqualWithDelta($planned->expenditureKcal, F::ACC);
    }
});

it('NUT-023 deload week runs at expenditure', function () {
    $cut = F::phase(PhaseType::FatLoss, ratePercent: -0.5);
    expect(F::targets(phase: $cut, week: F::week(deload: true))->weeklyKcal())->toEqualWithDelta(19600, F::ACC);
});

it('PLAN-022 deload in fat loss and mini cut runs at maintenance', function () {
    foreach ([PhaseType::FatLoss, PhaseType::MiniCut] as $type) {
        $phase = F::phase($type, ratePercent: -0.75);

        expect(F::targets(phase: $phase, week: F::week(deload: true))->weeklyKcal())->toEqualWithDelta(19600, F::ACC)
            // -0.75% x 90 x 7700 / 7 = -742.5 per day
            ->and(F::targets(phase: $phase)->weeklyKcal())->toEqualWithDelta(19600 - 7 * 742.5, F::ACC);
    }
});

it('PLAN-024 deload in muscle gain and maintenance runs at maintenance', function () {
    $gain = F::phase(PhaseType::MuscleGain, ratePercent: 0.25);

    expect(F::targets(phase: $gain, week: F::week(deload: true))->weeklyKcal())->toEqualWithDelta(19600, F::ACC)
        // a normal gain week adds 0.25% x 90 x 7700 / 7 = 247.5 per day
        ->and(F::targets(phase: $gain)->weeklyKcal())->toEqualWithDelta(19600 + 7 * 247.5, F::ACC)
        ->and(F::targets(phase: F::phase(PhaseType::Maintenance), week: F::week(deload: true))->weeklyKcal())->toEqualWithDelta(19600, F::ACC);
});

// SAFE-005 rate cap

it('SAFE-005 loss rate clamped to one percent per week', function () {
    $entered = F::targets(phase: F::phase(PhaseType::FatLoss, ratePercent: -1.5));
    $capped = F::targets(phase: F::phase(PhaseType::FatLoss, ratePercent: -1.0));

    expect($entered)->toEqual($capped)
        // -1.0% x 90 x 7700 / 7 = -990 per day
        ->and($entered->weeklyKcal())->toEqualWithDelta(19600 - 7 * 990, F::ACC)
        ->and($entered->days[0]->carbsG)->toEqualWithDelta((1610 - 792 - 452.5) / 4, F::ACC); // 91.375
});

it('SAFE-005 slower rates are not changed', function () {
    $week = F::targets(phase: F::phase(PhaseType::FatLoss, ratePercent: -0.8));
    expect($week->weeklyKcal())->toEqualWithDelta(19600 - 7 * 792, F::ACC); // 0.8% = 792 per day
});

// SAFE-008 protein holds in a deficit

it('SAFE-008 protein does not drop in a deficit', function () {
    $maint = F::base();
    foreach ([-0.25, -0.5, -1.0, -1.5] as $rate) {
        foreach (F::targets(phase: F::phase(PhaseType::FatLoss, ratePercent: $rate))->days as $day) {
            expect($day->proteinG)->toEqualWithDelta($maint->days[0]->proteinG, F::ACC);
        }
    }
});

// SAFE-009 / SAFE-010 fat floors

it('SAFE-009 fat never below fifteen percent of any day', function () {
    $days = F::uniformDays(2000, DayType::Rest, count: 6);
    $days[] = new PlannedDay(5000, DayType::Hard);
    foreach (F::targets(days: $days)->days as $day) {
        expect($day->fatG * 9)->toBeGreaterThanOrEqual(0.15 * $day->kcal() - F::ACC);
    }
    foreach (F::targets(phase: F::phase(PhaseType::FatLoss, ratePercent: -0.5))->days as $day) {
        expect($day->fatG * 9)->toBeGreaterThanOrEqual(0.15 * $day->kcal() - F::ACC);
    }
});

it('SAFE-010 fat keeps five grams per meal', function () {
    // 12 meals need 60 g fat, above the 52.272 g lean-mass floor.
    $week = F::targets(profile: F::profile(bucket: BodyFatBucket::Lowest), days: F::uniformDays(1800, DayType::Rest), meals: 12);

    expect($week->days[0]->fatG)->toEqualWithDelta(60, F::ACC)
        ->and($week->mealsPerDay)->toBe(12);
});

// SAFE-011 BMI floor

it('SAFE-011 cut refused at BMI seventeen', function () {
    // 68 kg at 200 cm is BMI exactly 17.
    $thin = F::profile(heightCm: 200, weightKg: 68);
    $refusal = F::refusal(fn () => F::targets(
        profile: $thin, phase: F::phase(PhaseType::FatLoss, ratePercent: -0.5), days: F::uniformDays(2500, DayType::Rest)));

    expect($refusal)->toBeInstanceOf(TargetRefusal::class)
        ->toMatchObject(['reason' => TargetRefusalReason::BmiAtOrBelowFloor, 'bmi' => 17.0]);
});

it('SAFE-011 cut refused below BMI seventeen', function () {
    $thin = F::profile(heightCm: 200, weightKg: 60); // BMI 15
    $refusal = F::refusal(fn () => F::targets(
        profile: $thin, phase: F::phase(PhaseType::FatLoss, ratePercent: -0.5), days: F::uniformDays(2500, DayType::Rest)));

    expect($refusal)->toBeInstanceOf(TargetRefusal::class)
        ->toMatchObject(['reason' => TargetRefusalReason::BmiAtOrBelowFloor, 'bmi' => 15.0]);
});

it('SAFE-011 cut allowed at BMI seventeen point five', function () {
    // 70 kg at 200 cm = 17.5; -0.5% x 70 x 7700 / 7 = -385 per day
    $week = F::targets(
        profile: F::profile(heightCm: 200, weightKg: 70), phase: F::phase(PhaseType::FatLoss, ratePercent: -0.5),
        days: F::uniformDays(2500, DayType::Rest));

    expect($week->days[0]->kcal())->toEqualWithDelta(2115, F::ACC);
});

it('SAFE-011 floor only stops cuts', function () {
    $thin = F::profile(heightCm: 200, weightKg: 68);
    $days = F::uniformDays(2500, DayType::Rest);

    expect(F::targets(profile: $thin, phase: F::phase(PhaseType::Maintenance), days: $days)->days)->toHaveCount(7)
        ->and(F::targets(profile: $thin, phase: F::phase(PhaseType::MuscleGain, ratePercent: 0.25), days: $days)->days)->toHaveCount(7)
        // a deload week in a cut runs at maintenance, so it is not a cut
        ->and(F::targets(
            profile: $thin, phase: F::phase(PhaseType::FatLoss, ratePercent: -0.5), week: F::week(deload: true), days: $days,
        )->days)->toHaveCount(7);
});

// SAFE-012 refusal instead of a target

it('SAFE-012 cut that breaks carb minimum is refused naming the day', function () {
    // -1% = -990: days 0-5 land at 2010, day 6 at 510 kcal, below 4 x 198 g protein alone.
    $days = F::uniformDays(3000, DayType::Hard, count: 6);
    $days[] = new PlannedDay(1500, DayType::Rest);
    $refusal = F::refusal(fn () => F::targets(phase: F::phase(PhaseType::FatLoss, ratePercent: -1.0), days: $days));

    expect($refusal)->toBeInstanceOf(TargetRefusal::class)
        ->toMatchObject(['reason' => TargetRefusalReason::BreaksFloor, 'dayIndex' => 6]);
});

it('SAFE-012 carbs just above the meal minimum are accepted', function () {
    // Lowest bucket: fat on the lean arm 52.272 g (470.448 kcal), protein 792 kcal. 4 meals need 20 g carbs.
    // 1346.448 kcal leaves 84 kcal = 21 g.
    $week = F::targets(profile: F::profile(bucket: BodyFatBucket::Lowest), days: F::uniformDays(1346.448, DayType::Rest));

    expect($week->days[0]->fatG)->toEqualWithDelta(52.272, F::ACC)
        ->and($week->days[0]->carbsG)->toEqualWithDelta(21, F::ACC);
});

it('SAFE-012 carbs just below the meal minimum are refused', function () {
    // 1338.448 kcal leaves 76 kcal = 19 g, under the 20 g minimum.
    $refusal = F::refusal(fn () => F::targets(
        profile: F::profile(bucket: BodyFatBucket::Lowest), days: F::uniformDays(1338.448, DayType::Rest)));

    expect($refusal)->toBeInstanceOf(TargetRefusal::class)
        ->toMatchObject(['reason' => TargetRefusalReason::BreaksFloor, 'dayIndex' => 0]);
});

it('SAFE-012 refusals carry a recommendation', function () {
    expect(TargetRefusal::bmiAtOrBelowFloor(17)->recommendation())->toBe(TargetRecommendation::RaiseToMaintenance)
        ->and(TargetRefusal::breaksFloor(0)->recommendation())->toBe(TargetRecommendation::RaiseCaloriesOrEndPhase)
        ->and(TargetRefusal::breaksFloor(0)->reason)->toBe(TargetRefusalReason::BreaksFloor);
});

// TBL-032 warnings only

it('TBL-032 below bound flags without changing the split', function () {
    // Light days at 2000: fat 500 kcal, carbs (2000 - 792 - 500) / 4 = 177 g = 1.095 g/lb of lean (< 1.5)
    foreach (F::targets(days: F::uniformDays(2000, DayType::Light))->days as $day) {
        expect($day->carbsG)->toEqualWithDelta(177, F::ACC)
            ->and($day->carbBoundsWarning)->toBe(CarbBoundsWarning::Below);
    }
});

it('TBL-032 above bound flags and in range does not', function () {
    $week = F::base();
    // lean 73.35 kg = 161.71 lb. Rest 277 g = 1.71 g/lb (> 1.0); hard 402 g = 2.49 g/lb (in 1.5-3.0).
    expect($week->days[0]->carbBoundsWarning)->toBe(CarbBoundsWarning::Above)
        ->and($week->days[1]->carbBoundsWarning)->toBeNull()
        ->and($week->days[0]->carbsG)->toEqualWithDelta(277, F::ACC);
});

it('TBL-032 rest day inside bound has no flag', function () {
    // Rest at 1700: fat on the 0.66 g/kg floor (48.411 g), carbs 118.075 g = 0.73 g/lb
    $week = F::targets(days: F::uniformDays(1700, DayType::Rest));

    expect($week->days[0]->carbsG)->toEqualWithDelta((1700 - 792 - 9 * 48.411) / 4, F::ACC)
        ->and($week->days[0]->carbBoundsWarning)->toBeNull();
});

it('TBL-032 deload days are never flagged', function () {
    foreach (F::targets(days: F::uniformDays(2000, DayType::Deload))->days as $day) {
        expect($day->carbBoundsWarning)->toBeNull();
    }
});
