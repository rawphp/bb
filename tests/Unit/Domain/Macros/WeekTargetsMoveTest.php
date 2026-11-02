<?php

use App\Domain\Macros\CarbBoundsWarning;
use App\Domain\Macros\DayTarget;
use App\Domain\Macros\MoveRefusal;
use App\Domain\Macros\MoveRefusalReason;
use App\Domain\Macros\PlannedDay;
use App\Domain\Macros\WeekTargets;
use App\Domain\Model\BodyFatBucket;
use App\Domain\Model\DayType;
use Tests\Unit\Domain\Macros\MacroFixtures as F;

// NUT-021, NUT-022 and NUT-024: moving calories between days. Base week average 2800 -> band 2240 to 3500.

it('NUT-021 move keeps weekly total and lands on carbs', function () {
    $base = F::base();
    $moved = $base->moving(kcal: 200, from: 0, to: 1);

    expect($moved->weeklyKcal())->toEqualWithDelta($base->weeklyKcal(), F::ACC)
        ->and($moved->days[0]->kcal())->toEqualWithDelta(2400, F::ACC)
        ->and($moved->days[1]->kcal())->toEqualWithDelta(3300, F::ACC)
        ->and($moved->days[0]->carbsG)->toEqualWithDelta(227, F::ACC) // 277 - 50
        ->and($moved->days[1]->carbsG)->toEqualWithDelta(452, F::ACC) // 402 + 50
        ->and($moved->days[2])->toEqual($base->days[2]);
});

it('NUT-021 invalid moves are refused', function (float $kcal, int $from, int $to) {
    expect(F::refusal(fn () => F::base()->moving(kcal: $kcal, from: $from, to: $to)))
        ->toBeInstanceOf(MoveRefusal::class)
        ->toMatchObject(['reason' => MoveRefusalReason::Invalid, 'dayIndex' => null]);
})->with([
    'zero kcal' => [0.0, 0, 1],
    'negative kcal' => [-50.0, 0, 1],
    'same day' => [100.0, 2, 2],
    'from before the week' => [100.0, -1, 1],
    'to after the week' => [100.0, 0, 7],
]);

it('NUT-022 protein and fat never move', function () {
    foreach (F::base()->moving(kcal: 300, from: 4, to: 2)->days as $day) {
        expect($day->proteinG)->toEqualWithDelta(198, F::ACC)
            ->and($day->fatG)->toEqualWithDelta(700.0 / 9, F::ACC);
    }
});

it('NUT-022 day at exactly eighty percent is accepted', function () {
    $moved = F::base()->moving(kcal: 360, from: 0, to: 1);

    expect($moved->days[0]->kcal())->toEqualWithDelta(2240, F::ACC)
        ->and($moved->days[1]->kcal())->toEqualWithDelta(3460, F::ACC);
});

it('NUT-022 day below eighty percent is refused', function () {
    expect(F::refusal(fn () => F::base()->moving(kcal: 361, from: 0, to: 1)))
        ->toBeInstanceOf(MoveRefusal::class)
        ->toMatchObject(['reason' => MoveRefusalReason::OutsideBand, 'dayIndex' => 0]);
});

it('NUT-022 day at exactly one twenty five percent is accepted', function () {
    $moved = F::base()->moving(kcal: 400, from: 4, to: 1);

    expect($moved->days[1]->kcal())->toEqualWithDelta(3500, F::ACC)
        ->and($moved->days[4]->kcal())->toEqualWithDelta(2500, F::ACC);
});

it('NUT-022 day above one twenty five percent is refused', function () {
    expect(F::refusal(fn () => F::base()->moving(kcal: 401, from: 4, to: 1)))
        ->toBeInstanceOf(MoveRefusal::class)
        ->toMatchObject(['reason' => MoveRefusalReason::OutsideBand, 'dayIndex' => 1]);
});

it('NUT-024 completed days are locked out', function () {
    $planned = F::mixedDays();
    $planned[0] = new PlannedDay($planned[0]->expenditureKcal, $planned[0]->type, isCompleted: true);
    $base = F::targets(days: $planned);

    expect($base->days[0]->isCompleted)->toBeTrue()
        ->and(F::refusal(fn () => $base->moving(kcal: 100, from: 0, to: 1)))
        ->toBeInstanceOf(MoveRefusal::class)
        ->toMatchObject(['reason' => MoveRefusalReason::DayCompleted, 'dayIndex' => 0])
        ->and(F::refusal(fn () => $base->moving(kcal: 100, from: 1, to: 0)))
        ->toBeInstanceOf(MoveRefusal::class)
        ->toMatchObject(['reason' => MoveRefusalReason::DayCompleted, 'dayIndex' => 0])
        ->and($base->moving(kcal: 100, from: 1, to: 2)->days)->toHaveCount(7);
});

it('SAFE-012 move that breaks the carb minimum is refused', function () {
    // Seven rest days at 1400 kcal: fat 52.272 g (lean floor), carbs 34.388 g, minimum 20 g.
    $base = F::targets(profile: F::profile(bucket: BodyFatBucket::Lowest), days: F::uniformDays(1400, DayType::Rest));

    expect($base->days[0]->carbsG)->toEqualWithDelta(34.388, F::ACC)
        ->and($base->moving(kcal: 50, from: 0, to: 1)->days[0]->carbsG)->toEqualWithDelta(21.888, F::ACC)
        ->and(F::refusal(fn () => $base->moving(kcal: 60, from: 0, to: 1))) // 19.388 g left
        ->toBeInstanceOf(MoveRefusal::class)
        ->toMatchObject(['reason' => MoveRefusalReason::BreaksFloor, 'dayIndex' => 0]);
});

it('SAFE-009 move onto a day that would put fat below fifteen percent is refused', function () {
    // Seven hard days at 4000: fat 25% = 1000 kcal. A 500 kcal cut leaves 3500 kcal days and fat at the 15%
    // floor of the new biggest day, 525 kcal (58.333 g); fat gave 475 kcal, carbs 25 kcal.
    $base = F::targets(days: F::uniformDays(4000, DayType::Hard));
    $cut = $base->adjusted(byKcalPerDay: -500, profile: F::profile());

    expect($cut->days[0]->fatG)->toEqualWithDelta(525.0 / 9, F::ACC)
        ->and($cut->days[0]->kcal())->toEqualWithDelta(3500, F::ACC)
        // 100 kcal onto day 1 is 3600 (inside the 4375 band top) but 15% of 3600 is 540 kcal, more than the 525 kcal of fat.
        ->and(F::refusal(fn () => $cut->moving(kcal: 100, from: 0, to: 1)))
        ->toBeInstanceOf(MoveRefusal::class)
        ->toMatchObject(['reason' => MoveRefusalReason::BreaksFloor, 'dayIndex' => 1]);
});

it('TBL-032 carbs exactly on a bound are not flagged', function () {
    // Lean 45.359237 kg is 100 lb, so grams of carbs are grams per lb x 100. Fat 100 g, protein 200 g.
    // Days: rest 90 g, light 140 g, hard 290 g (2060 / 2260 / 2900 kcal, average 2406.67, band 1925 to 3008).
    $week = new WeekTargets(
        days: [
            new DayTarget(DayType::Rest, isCompleted: false, proteinG: 200, fatG: 100, carbsG: 90, carbBoundsWarning: null),
            new DayTarget(DayType::Light, isCompleted: false, proteinG: 200, fatG: 100, carbsG: 140, carbBoundsWarning: null),
            new DayTarget(DayType::Hard, isCompleted: false, proteinG: 200, fatG: 100, carbsG: 290, carbBoundsWarning: null),
        ],
        leanMassKg: 45.359237,
        mealsPerDay: 4,
    );
    $toRest = $week->moving(kcal: 40, from: 2, to: 0); // rest 100 g = 1.0 g/lb, the top of 0.5 to 1.0
    $toLight = $week->moving(kcal: 40, from: 2, to: 1); // light 150 g = 1.5 g/lb, the bottom of 1.5 to 3.0

    expect($toRest->days[0]->carbsG)->toEqualWithDelta(100, F::ACC)
        ->and($toRest->days[0]->carbBoundsWarning)->toBeNull()
        ->and($toLight->days[1]->carbsG)->toEqualWithDelta(150, F::ACC)
        ->and($toLight->days[1]->carbBoundsWarning)->toBeNull()
        // a hair either side is flagged
        ->and($week->moving(kcal: 41, from: 2, to: 0)->days[0]->carbBoundsWarning)->toBe(CarbBoundsWarning::Above)
        ->and($week->moving(kcal: 39, from: 2, to: 1)->days[1]->carbBoundsWarning)->toBe(CarbBoundsWarning::Below);
});

it('TBL-032 moving recomputes warnings without changing the split', function () {
    // Light days at 2000: 177 g carbs each, 1.095 g/lb, all flagged below 1.5.
    $moved = F::targets(days: F::uniformDays(2000, DayType::Light))->moving(kcal: 300, from: 1, to: 0);

    expect($moved->days[0]->carbsG)->toEqualWithDelta(252, F::ACC) // 1.558 g/lb, in range
        ->and($moved->days[0]->carbBoundsWarning)->toBeNull()
        ->and($moved->days[1]->carbsG)->toEqualWithDelta(102, F::ACC)
        ->and($moved->days[1]->carbBoundsWarning)->toBe(CarbBoundsWarning::Below);
});
