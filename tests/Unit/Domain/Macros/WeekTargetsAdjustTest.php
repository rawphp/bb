<?php

use App\Domain\Macros\DayTarget;
use App\Domain\Macros\PlannedDay;
use App\Domain\Macros\TargetRefusal;
use App\Domain\Macros\TargetRefusalReason;
use App\Domain\Macros\WeekTargets;
use App\Domain\Model\BodyFatBucket;
use App\Domain\Model\DayType;
use Tests\Unit\Domain\Macros\MacroFixtures as F;

// NUT-015 / NUT-016 plus the SAFE rails on a weekly-review change of the same kcal on every day.

it('NUT-015 small cut comes out of fat first and leaves carbs', function () {
    // New biggest day 3000 -> floor 15% = 50 g; fat 77.778 g can give 27.78 g, the cut needs 11.11 g.
    $cut = F::base()->adjusted(byKcalPerDay: -100, profile: F::profile());
    foreach ($cut->days as $day) {
        expect($day->fatG)->toEqualWithDelta(700.0 / 9 - 100.0 / 9, F::ACC);
    }
    expect($cut->days[0]->carbsG)->toEqualWithDelta(277, F::ACC)
        ->and($cut->days[1]->carbsG)->toEqualWithDelta(402, F::ACC)
        ->and($cut->weeklyKcal())->toEqualWithDelta(19600 - 700, F::ACC);
});

it('NUT-015 cut past the fat floor takes equal grams from every day of carbs', function () {
    // -300: floor is the lean-mass arm, 0.66 x 73.35 = 48.411 g (15% of 2800 is only 46.67 g).
    // Fat gives 77.778 - 48.411 = 29.367 g (264.3 kcal); the other 35.699 kcal is 8.925 g carbs on every day.
    $cut = F::base()->adjusted(byKcalPerDay: -300, profile: F::profile());
    foreach ($cut->days as $day) {
        expect($day->fatG)->toEqualWithDelta(48.411, F::ACC);
    }
    expect($cut->days[0]->carbsG)->toEqualWithDelta(277 - 8.92475, F::ACC)
        ->and($cut->days[1]->carbsG)->toEqualWithDelta(402 - 8.92475, F::ACC)
        ->and($cut->days[1]->carbsG - $cut->days[0]->carbsG)->toEqualWithDelta(125, F::ACC) // lead over rest day kept
        ->and($cut->days[0]->kcal())->toEqualWithDelta(2300, F::ACC)
        ->and($cut->days[1]->kcal())->toEqualWithDelta(2800, F::ACC);
});

it('NUT-015 protein never changes on a cut', function () {
    foreach (F::base()->adjusted(byKcalPerDay: -300, profile: F::profile())->days as $day) {
        expect($day->proteinG)->toEqualWithDelta(198, F::ACC);
    }
});

it('SAFE-012 cut that would break the carb minimum is refused', function () {
    // -1500: fat floor 48.411 g (lean arm) gives 29.37 g, the other 1235.7 kcal is 308.9 g carbs; rest day has 277 g.
    expect(F::refusal(fn () => F::base()->adjusted(byKcalPerDay: -1500, profile: F::profile())))
        ->toBeInstanceOf(TargetRefusal::class)
        ->toMatchObject(['reason' => TargetRefusalReason::BreaksFloor, 'dayIndex' => 0]);
});

it('SAFE-011 cut refused at BMI seventeen', function () {
    $thin = F::profile(heightCm: 200, weightKg: 68);

    expect(F::refusal(fn () => F::base()->adjusted(byKcalPerDay: -100, profile: $thin)))
        ->toBeInstanceOf(TargetRefusal::class)
        ->toMatchObject(['reason' => TargetRefusalReason::BmiAtOrBelowFloor, 'bmi' => 17.0])
        // raising calories is still fine at BMI 17
        ->and(F::base()->adjusted(byKcalPerDay: 100, profile: $thin)->days)->toHaveCount(7);
});

it('NUT-016 increase below the cap goes all to carbs', function () {
    // Hard-day carbs 402 g, cap 485.13 g, room 83.13 g; +200 kcal is +50 g.
    $more = F::base()->adjusted(byKcalPerDay: 200, profile: F::profile());

    expect($more->days[0]->carbsG)->toEqualWithDelta(327, F::ACC)
        ->and($more->days[1]->carbsG)->toEqualWithDelta(452, F::ACC);
    foreach ($more->days as $day) {
        expect($day->fatG)->toEqualWithDelta(700.0 / 9, F::ACC)
            ->and($day->proteinG)->toEqualWithDelta(198, F::ACC);
    }
});

it('NUT-016 increase beyond the cap splits between carbs and fat', function () {
    // +400 kcal: 83.127 g carbs fill the cap (332.5 kcal), the other 67.49 kcal splits 25% fat / 75% carbs.
    $room = F::carbCapG() - 402;
    $remainder = 400 - 4 * $room;
    $more = F::base()->adjusted(byKcalPerDay: 400, profile: F::profile());
    $carbsAdded = $room + 0.75 * $remainder / 4;

    expect($more->days[1]->carbsG)->toEqualWithDelta(402 + $carbsAdded, F::ACC)
        ->and($more->days[0]->carbsG)->toEqualWithDelta(277 + $carbsAdded, F::ACC)
        ->and($more->days[0]->fatG)->toEqualWithDelta(700.0 / 9 + 0.25 * $remainder / 9, F::ACC)
        ->and($more->days[1]->kcal())->toEqualWithDelta(3500, F::ACC)
        ->and($more->days[0]->proteinG)->toEqualWithDelta(198, F::ACC);
});

it('NUT-016 training days already at the cap split everything', function () {
    // Start with hard-day carbs above the cap: 7 hard days at 3800 kcal -> carbs 580.25 g (3.59 g/lb).
    $base = F::targets(days: F::uniformDays(3800, DayType::Hard));
    $fat = $base->days[0]->fatG;
    $more = $base->adjusted(byKcalPerDay: 100, profile: F::profile());

    expect($more->days[0]->fatG)->toEqualWithDelta($fat + 25.0 / 9, F::ACC)
        ->and($more->days[0]->carbsG)->toEqualWithDelta($base->days[0]->carbsG + 75.0 / 4, F::ACC);
});

// SAFE-009 / SAFE-010 fat floor arms of a cut

it('SAFE-009 cut stops fat at fifteen percent of the new biggest day', function () {
    // Lowest bucket (lean arm 52.272 g). Six rest days at 2500 and one hard day at 5000: fat starts at
    // 15% of 5000 = 750 kcal (83.333 g). Cut 300: new biggest day 4700, floor 705 kcal = 78.333 g, which
    // beats the lean arm (52.272 g) and 4 meals x 5 g. Fat gives 45 kcal; the other 255 kcal is 63.75 g carbs.
    $planned = F::uniformDays(2500, DayType::Rest, count: 6);
    $planned[] = new PlannedDay(5000, DayType::Hard);
    $base = F::targets(profile: F::profile(bucket: BodyFatBucket::Lowest), days: $planned);
    expect($base->days[0]->fatG)->toEqualWithDelta(750.0 / 9, F::ACC);

    $cut = $base->adjusted(byKcalPerDay: -300, profile: F::profile());
    foreach ($cut->days as $day) {
        expect($day->fatG)->toEqualWithDelta(705.0 / 9, F::ACC)
            ->and($day->fatG * 9)->toBeGreaterThanOrEqual(0.15 * $day->kcal() - F::ACC);
    }
    expect($cut->days[0]->kcal())->toEqualWithDelta(2200, F::ACC)
        ->and($cut->days[6]->kcal())->toEqualWithDelta(4700, F::ACC)
        ->and($cut->days[0]->carbsG)->toEqualWithDelta(239.5 - 63.75, F::ACC)
        ->and($cut->days[6]->carbsG)->toEqualWithDelta(864.5 - 63.75, F::ACC);
});

it('SAFE-010 cut keeps five grams of fat per meal', function () {
    // Lowest bucket, 12 meals: fat 60 g (5 g x 12) beats the lean arm (52.272 g) and 15% of 1700 (28.3 g).
    // Fat cannot give anything, so the whole 100 kcal cut is 25 g carbs: 117 - 25 = 92 g.
    $base = F::targets(profile: F::profile(bucket: BodyFatBucket::Lowest), days: F::uniformDays(1800, DayType::Rest), meals: 12);
    expect($base->days[0]->carbsG)->toEqualWithDelta(117, F::ACC);

    foreach ($base->adjusted(byKcalPerDay: -100, profile: F::profile())->days as $day) {
        expect($day->fatG)->toEqualWithDelta(60, F::ACC)
            ->and($day->carbsG)->toEqualWithDelta(92, F::ACC)
            ->and($day->kcal())->toEqualWithDelta(1700, F::ACC);
    }
});

// SAFE-012 carb minimum boundary

it('SAFE-012 cut leaving carbs just above the meal minimum is accepted', function () {
    // Lowest bucket, 1800 rest days: fat on the lean arm 52.272 g, carbs 134.388 g. Fat has nothing to give,
    // so a cut of 453.552 kcal takes 113.388 g of carbs: 21 g left, minimum 4 x 5 = 20 g.
    foreach (F::lowestRestWeek()->adjusted(byKcalPerDay: -453.552, profile: F::profile())->days as $day) {
        expect($day->carbsG)->toEqualWithDelta(21, F::ACC)
            ->and($day->fatG)->toEqualWithDelta(52.272, F::ACC);
    }
});

it('SAFE-012 cut leaving carbs just below the meal minimum is refused', function () {
    // Same week, a cut of 461.552 kcal leaves 19 g of carbs.
    expect(F::refusal(fn () => F::lowestRestWeek()->adjusted(byKcalPerDay: -461.552, profile: F::profile())))
        ->toBeInstanceOf(TargetRefusal::class)
        ->toMatchObject(['reason' => TargetRefusalReason::BreaksFloor, 'dayIndex' => 0]);
});

// NUT-016 which days set the carb headroom

it('NUT-016 increase on an all rest week goes all to carbs', function () {
    // No training day, so there is no cap to reach: 1800 rest days, +1000 kcal is +250 g carbs on every day.
    foreach (F::lowestRestWeek()->adjusted(byKcalPerDay: 1000, profile: F::profile())->days as $day) {
        expect($day->fatG)->toEqualWithDelta(52.272, F::ACC)
            ->and($day->carbsG)->toEqualWithDelta(134.388 + 250, F::ACC)
            ->and($day->kcal())->toEqualWithDelta(2800, F::ACC);
    }
});

it('NUT-016 rest day carbs do not use up the training day headroom', function () {
    // Lean 80 kg = 176.37 lb, cap 529.11 g. Rest day has 450 g carbs, light day 200 g. Only the light day counts:
    // headroom 329 g, so +400 kcal (+100 g) is all carbs. Counting the rest day would leave 79 g and split the rest.
    $week = new WeekTargets(
        days: [
            new DayTarget(DayType::Rest, isCompleted: false, proteinG: 160, fatG: 60, carbsG: 450, carbBoundsWarning: null),
            new DayTarget(DayType::Light, isCompleted: false, proteinG: 160, fatG: 60, carbsG: 200, carbBoundsWarning: null),
        ],
        leanMassKg: 80,
        mealsPerDay: 4,
    );
    $more = $week->adjusted(byKcalPerDay: 400, profile: F::profile());

    expect($more->days[0]->carbsG)->toEqualWithDelta(550, F::ACC)
        ->and($more->days[1]->carbsG)->toEqualWithDelta(300, F::ACC);
    foreach ($more->days as $day) {
        expect($day->fatG)->toEqualWithDelta(60, F::ACC);
    }
});

// SAFE-009 on an increase

it('SAFE-009 increase from the fat floor raises fat to fifteen percent of the biggest day', function () {
    // Lowest bucket: six rest days at 1800 and one hard day at 2200. Fat sits on the lean arm, 52.272 g (470.448 kcal).
    // +1000 kcal fits the hard day's carb headroom (234.388 -> 484.388 g, cap 523.8 g), but the new biggest day
    // is 3200 kcal and 15% of that is 480 kcal = 53.333 g. Fat rises to 53.333 g, carbs give back the same
    // kcal: rest 2800 kcal = 792 + 480 + 4 x 382, hard 3200 kcal = 792 + 480 + 4 x 482.
    $planned = F::uniformDays(1800, DayType::Rest, count: 6);
    $planned[] = new PlannedDay(2200, DayType::Hard);
    $base = F::targets(profile: F::profile(bucket: BodyFatBucket::Lowest), days: $planned);
    expect($base->days[0]->fatG)->toEqualWithDelta(52.272, F::ACC)
        ->and($base->days[6]->carbsG)->toEqualWithDelta(234.388, F::ACC);

    $more = $base->adjusted(byKcalPerDay: 1000, profile: F::profile());
    foreach ($more->days as $day) {
        expect($day->fatG)->toEqualWithDelta(480.0 / 9, F::ACC)
            ->and($day->proteinG)->toEqualWithDelta(198, F::ACC)
            ->and($day->fatG * 9)->toBeGreaterThanOrEqual(0.15 * $day->kcal() - F::ACC);
    }
    expect($more->days[0]->kcal())->toEqualWithDelta(2800, F::ACC)
        ->and($more->days[6]->kcal())->toEqualWithDelta(3200, F::ACC)
        ->and($more->days[0]->carbsG)->toEqualWithDelta(382, F::ACC)
        ->and($more->days[6]->carbsG)->toEqualWithDelta(482, F::ACC);
});

it('NUT-016 zero change leaves the week alone', function () {
    $base = F::base();
    expect($base->adjusted(byKcalPerDay: 0, profile: F::profile()))->toEqual($base);
});

it('NUT-020 an empty week has no targets and adjusts to itself', function () {
    $empty = F::targets(days: []);

    expect($empty->days)->toBe([])
        ->and($empty->weeklyKcal())->toEqualWithDelta(0, F::ACC)
        ->and($empty->dailyAverageKcal())->toEqualWithDelta(0, F::ACC)
        ->and($empty->adjusted(-100, F::profile()))->toBe($empty)
        ->and($empty->adjusted(100, F::profile()))->toBe($empty);
});
