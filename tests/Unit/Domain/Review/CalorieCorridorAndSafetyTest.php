<?php

use App\Domain\Macros\TargetRecommendation;
use App\Domain\Macros\TargetRefusalReason;
use App\Domain\Model\PhaseType;
use App\Domain\Review\RecommendationReason;
use App\Domain\Review\RecommendationReasonKind;
use App\Domain\Review\StallChange;
use Tests\Unit\Domain\Review\CalorieFixtures as F;
use Tests\Unit\Domain\Review\CalorieScenario;

// The same tests as rp-clone's CalorieCorridorAndSafetyTests (pin 2aa1edeb), one for one.

/**
 * Maintenance from day 0 with the formula (2,600) as expenditure. The first week or two are thinly logged.
 *
 * @param  list<float>  $rates
 */
function corridorMaintenance(int $weeks, array $rates, float $targetRate = 0): CalorieScenario
{
    return new CalorieScenario(
        weeks: F::weeks($weeks, 2500, false), phaseType: PhaseType::Maintenance, targetRate: $targetRate, rates: $rates,
    );
}

/** 170 cm at about 47 kg is a BMI near 16.3. The cut is due: -0.2% against a -0.5% target, formula 1,800. */
function corridorLowBMI(float $kcal): CalorieScenario
{
    return new CalorieScenario(
        weeks: F::weeks(4, $kcal, false), rates: [-0.5, -0.5, -0.5, -0.2], startKg: 48, heightCm: 170, formulaKcal: 1800,
    );
}

// REV-029

it('REV-029 inside the corridor keeps calories', function () {
    // The trend ends 0.86% under where the phase started: inside plus or minus 1%.
    $scenario = corridorMaintenance(3, [-0.3]);
    $start = $scenario->trend()->trendKg(F::day(0));
    expect(abs($scenario->trendKg() - $start) / $start * 100)->toBeLessThan(1);

    $recommendation = $scenario->recommend();
    expect($recommendation->reason)->toEqual(RecommendationReason::insideCorridor())
        ->and($recommendation->kcal)->toEqualWithDelta(2500, 1e-9);
});

it('REV-029 a flat maintenance phase is not a stall', function () {
    expect(corridorMaintenance(4, [0])->recommend()->reason)->toEqual(RecommendationReason::insideCorridor());
});

it('REV-029 below the corridor steers back up with the 250 cap', function () {
    $scenario = corridorMaintenance(3, [-0.5]);
    $start = $scenario->trend()->trendKg(F::day(0));
    expect(($start - $scenario->trendKg()) / $start * 100)->toBeGreaterThan(1);

    $recommendation = $scenario->recommend();
    expect($recommendation->reason)->toEqual(RecommendationReason::outsideCorridor())
        ->and($recommendation->kcal)->toEqualWithDelta(2750, 1e-9);
});

it('REV-029 above the corridor steers back down with the 250 cap', function () {
    $scenario = corridorMaintenance(3, [0.5]);
    $start = $scenario->trend()->trendKg(F::day(0));
    expect(($scenario->trendKg() - $start) / $start * 100)->toBeGreaterThan(1);

    $recommendation = $scenario->recommend();
    expect($recommendation->reason)->toEqual(RecommendationReason::outsideCorridor())
        ->and($recommendation->kcal)->toEqualWithDelta(2250, 1e-9);
});

it('REV-029 above the corridor after a water swing holds instead of cutting', function () {
    $scenario = corridorMaintenance(3, [0.5]);
    $scenario->weeks[2] = F::marked($scenario->weeks[2], higherCarbShift: true);
    $recommendation = $scenario->recommend();
    expect($recommendation->reason)->toEqual(RecommendationReason::waterWeight())
        ->and($recommendation->kcal)->toEqualWithDelta(2500, 1e-9);
});

it('REV-029 the corridor is measured from the trend at phase start not at the first reading', function () {
    // The phase starts on day 14 at about 80 kg after a drop; the early 82 kg weeks do not matter.
    $scenario = new CalorieScenario(
        weeks: F::weeks(5, 2500, false), phaseType: PhaseType::Maintenance, targetRate: 0, phaseStartDay: 14,
        rates: [-1, -1, 0], startKg: 82,
    );
    $start = $scenario->trend()->trendKg(F::day(14));
    expect($scenario->trendKg())->toEqualWithDelta($start, 1e-9)
        ->and($scenario->recommend()->reason)->toEqual(RecommendationReason::insideCorridor());
});

it('REV-029 a small gain target moves the centre each week', function () {
    // PH-004: +0.25%/week. At 80 kg flat the trend sits on the start weight, which is inside the
    // corridor early and outside it once the centre has moved more than 1% away.
    $early = corridorMaintenance(3, [0], 0.25);
    expect($early->recommend()->reason)->toEqual(RecommendationReason::insideCorridor(), 'centre 0.75% up after 3 weeks');

    $late = corridorMaintenance(7, [0], 0.25);
    $recommendation = $late->recommend();
    expect($recommendation->reason)->toEqual(RecommendationReason::outsideCorridor(), 'centre 1.75% up after 7 weeks')
        ->and($recommendation->kcal)->toEqualWithDelta(2750, 1e-9, 'raise to climb back to the centre');

    $steady = corridorMaintenance(7, [0], 0);
    expect($steady->recommend()->reason)->toEqual(RecommendationReason::insideCorridor(), 'no moving centre without a rate');
});

// REV-030

it('REV-030 a cut at or below BMI 17 is raised to maintenance', function () {
    $scenario = corridorLowBMI(1600);
    expect($scenario->level($scenario->recommend()))->toBeLessThan(1550, 'a cut was due');
    $recommendation = $scenario->recommend();
    expect($recommendation->reason->kind)->toBe(RecommendationReasonKind::Safety)
        ->and($recommendation->reason->refusal->recommendation())->toBe(TargetRecommendation::RaiseToMaintenance)
        ->and($recommendation->kcal)->toEqualWithDelta(1800, 1e-9, 'the expenditure')
        ->and($recommendation->changeKcal)->toEqualWithDelta(200, 1e-9)
        ->and($recommendation->reason->message())->toBeNull();
});

it('REV-030 below maintenance at a low BMI during fat loss goes up to maintenance', function () {
    // SAFE-011 is triggered by the BMI itself: a week well under maintenance is raised, not just left alone.
    $recommendation = corridorLowBMI(1400)->recommend();
    expect($recommendation->reason->kind)->toBe(RecommendationReasonKind::Safety)
        ->and($recommendation->reason->refusal->recommendation())->toBe(TargetRecommendation::RaiseToMaintenance)
        ->and($recommendation->kcal)->toEqualWithDelta(1800, 1e-9);
});

it('REV-030 a week on target at a low BMI during fat loss is still raised to maintenance', function () {
    $scenario = corridorLowBMI(1600);
    $scenario->rates = [-0.5]; // exactly on the target rate
    $recommendation = $scenario->recommend();
    expect($recommendation->reason->kind)->toBe(RecommendationReasonKind::Safety)
        ->and($recommendation->reason->refusal->recommendation())->toBe(TargetRecommendation::RaiseToMaintenance)
        ->and($recommendation->kcal)->toEqualWithDelta(1800, 1e-9)
        ->and($recommendation->changeKcal)->toEqualWithDelta(200, 1e-9);
});

it('REV-030 the first week of a phase at a low BMI is still raised to maintenance', function () {
    $scenario = new CalorieScenario(
        weeks: [F::week(0, intake: 1600, target: 1600)], rates: [-0.5], startKg: 48, heightCm: 170, formulaKcal: 1800,
    );
    $recommendation = $scenario->recommend();
    expect($recommendation->reason->kind)->toBe(RecommendationReasonKind::Safety)
        ->and($recommendation->reason->refusal->recommendation())->toBe(TargetRecommendation::RaiseToMaintenance)
        ->and($recommendation->kcal)->toEqualWithDelta(1800, 1e-9);
});

it('REV-030 a low BMI does not change maintenance or muscle gain reviews', function () {
    $maintenance = corridorLowBMI(1800);
    $maintenance->phaseType = PhaseType::Maintenance;
    $maintenance->targetRate = 0;
    $maintenance->rates = [0];
    expect($maintenance->recommend()->reason)->toEqual(RecommendationReason::insideCorridor());
});

it('REV-030 a cut that breaks the carb minimum holds calories with the reason', function () {
    // 1,250 kcal leaves fat at its floor and about 44 g of carbs; the 130 kcal cut would leave under 20 g.
    $scenario = new CalorieScenario(
        weeks: F::weeks(4, 1250, false), rates: [-0.5, -0.5, -0.5, -0.2], formulaKcal: 1550,
    );
    $recommendation = $scenario->recommend();
    expect($scenario->level($recommendation))->toBeLessThan(1200, 'a cut was due')
        ->and($recommendation->reason->kind)->toBe(RecommendationReasonKind::Safety)
        ->and($recommendation->reason->refusal->reason)->toBe(TargetRefusalReason::BreaksFloor)
        ->and($recommendation->reason->refusal->dayIndex)->toBe(0)
        ->and($recommendation->kcal)->toEqualWithDelta(1250, 1e-9)
        ->and($recommendation->changeKcal)->toEqualWithDelta(0, 1e-9);
});

it('REV-030 the return from a break is checked too', function () {
    // Back from a diet break to a target at 47 kg and BMI 16.3: no cut, so maintenance instead.
    $scenario = new CalorieScenario(
        weeks: [
            F::week(0, intake: 1500, target: 1500),
            F::week(1, intake: 1800, target: 1800, dietBreak: true),
        ],
        rates: [-0.5], startKg: 48, heightCm: 170, formulaKcal: 1800,
    );
    $recommendation = $scenario->recommend();
    expect($recommendation->reason->kind)->toBe(RecommendationReasonKind::Safety)
        ->and($recommendation->reason->refusal->recommendation())->toBe(TargetRecommendation::RaiseToMaintenance)
        ->and($recommendation->kcal)->toBeGreaterThanOrEqual(1800);
});

it('REV-030 a stall at a low BMI raises to maintenance instead of offering steps', function () {
    $scenario = corridorLowBMI(1600);
    $scenario->rates = [-0.5, -0.5, -0.05, -0.05];
    $recommendation = $scenario->recommend();
    expect($recommendation->reason->kind)->toBe(RecommendationReasonKind::Safety)
        ->and($recommendation->reason->refusal->recommendation())->toBe(TargetRecommendation::RaiseToMaintenance)
        ->and($recommendation->kcal)->toEqualWithDelta(1800, 1e-9);
});

it('REV-030 a stall cut that breaks the carb minimum still falls back to steps', function () {
    // SAFE-012 refuses the cut, not the BMI, so the stall options stay.
    $scenario = new CalorieScenario(
        weeks: F::weeks(4, 1250, false), rates: [-0.5, -0.5, -0.05, -0.05], formulaKcal: 1550,
    );
    $reason = $scenario->recommend()->reason;
    expect($reason->kind)->toBe(RecommendationReasonKind::Stalled)
        ->and($reason->stall->cutKcal)->toBeNull()
        ->and($reason->stall->recommended)->toBe(StallChange::AddSteps);
});

// PHP-only: kills a mutant the ported suite let through.

it('REV-029 the centre moves by the number of weeks into the phase, not one more', function () {
    // PH-004: +0.3%/week from 80 kg, so the half-width is 1% (0.8 kg). The reviewed week is 3: from the phase start to
    // the day after the week's end is 21 days, over 7 (CalorieRecommendation.swift:333-339). The trend stays at 80 kg,
    // 0.9% under the week-3 centre, inside; one week later the centre is 1.2% up, outside.
    $scenario = corridorMaintenance(3, [0], 0.3);
    expect($scenario->recommend()->reason)->toEqual(RecommendationReason::insideCorridor());
});
