<?php

use App\Domain\Model\PhaseType;
use App\Domain\Phases\StartingCalories;
use App\Domain\Review\RecommendationReason;
use App\Domain\Review\RecommendationReasonKind;
use App\Domain\Review\ReviewWeek;
use Tests\Unit\Domain\Review\CalorieFixtures as F;
use Tests\Unit\Domain\Review\CalorieScenario;

// The same tests as rp-clone's CalorieRecommendationTests (pin 2aa1edeb), one for one.

/**
 * Fat loss at -0.5%/week over four weeks; the older two are too thinly logged to measure, so the formula
 * (2,600) is the expenditure. The last week's rate is -0.2%, which is off target and not a stall.
 *
 * @param  list<float>  $rates
 */
function recFormulaScenario(float $kcal = 2250, array $rates = [-0.5, -0.5, -0.5, -0.2]): CalorieScenario
{
    return new CalorieScenario(weeks: F::weeks(4, $kcal, false), rates: $rates);
}

function recFormulaLevel(CalorieScenario $scenario): float
{
    return $scenario->formulaKcal + F::kcal($scenario->targetRate, $scenario->trendKg());
}

// REV-020

it('REV-020 a single weigh-in spike does not move the recommendation', function () {
    // Flat at 80 kg, then one 83 kg reading on the review's last day. Raw weight says +3.75% in a week;
    // the trend says +0.375%, inside the 1% maintenance corridor.
    $scenario = new CalorieScenario(
        weeks: F::weeks(3), phaseType: PhaseType::Maintenance, targetRate: 0, rates: [0], readingFactors: [20 => 83.0 / 80.0],
    );

    $rate = $scenario->trend()->weeklyRatePercent(F::day(20));
    expect($rate)->toEqualWithDelta(0.3 / 80 * 100, 1e-9);

    $recommendation = $scenario->recommend();
    expect($recommendation->reason)->toEqual(RecommendationReason::insideCorridor())
        ->and($recommendation->kcal)->toEqualWithDelta(2500, 1e-9);
});

it('REV-020 the rate comes from the trend not from readings', function () {
    // The same -0.2% trend with a noisy reading mid-week gives the recommendation the trend implies.
    $clean = recFormulaScenario();
    $noisy = recFormulaScenario();
    $noisy->readingFactors = [24 => 1.004];
    $rate = $noisy->trend()->weeklyRatePercent(F::day(27));
    expect($rate)->toEqualWithDelta(-0.2, 0.05)
        ->and($noisy->recommend()->reason)->toEqual($clean->recommend()->reason);
});

it('REV-020 not enough trend to read the week holds with a typed reason', function () {
    $scenario = new CalorieScenario(weeks: F::weeks(2));
    $scenario->trendThrough = 10; // week 1 ends on day 13
    $recommendation = $scenario->recommend();
    expect($recommendation->reason)->toEqual(RecommendationReason::noTrend())
        ->and($recommendation->kcal)->toEqualWithDelta(2500, 1e-9)
        ->and($recommendation->changeKcal)->toEqualWithDelta(0, 1e-9);
});

// REV-023

it('REV-023 uses measured expenditure once three weeks are eligible', function () {
    $scenario = new CalorieScenario(weeks: F::weeks(4, 2500), rates: [-0.5, -0.5, -0.5, -0.25]);
    $trend = $scenario->trend();
    $weekKcal = fn (int $index) => 2500 - F::trendChange($trend, $index) * 7700 / 7;
    $measured = 0.5 * $weekKcal(3) + 0.3 * $weekKcal(2) + 0.2 * $weekKcal(1);

    $recommendation = $scenario->recommend();
    expect(F::isMeasured($recommendation->expenditure))->toBeTrue()
        ->and($recommendation->expenditure->kcal)->toEqualWithDelta($measured, 1e-6);
    $level = $measured + F::kcal(-0.5, $scenario->trendKg());
    expect(abs($level - 2500))->toBeLessThan(250)
        ->and(abs($level - 2500))->toBeGreaterThan(50)
        ->and($recommendation->kcal)->toEqualWithDelta($level, 1e-6)
        ->and($recommendation->changeKcal)->toEqualWithDelta($level - 2500, 1e-6)
        ->and($recommendation->reason)->toEqual(RecommendationReason::towardTargetRate());
});

it('REV-023 falls back to the formula until there is a measured estimate', function () {
    $scenario = recFormulaScenario();
    $recommendation = $scenario->recommend();
    expect($recommendation->expenditure)->toEqual(StartingCalories::formula(2600));
    $level = recFormulaLevel($scenario);
    expect(abs($level - 2250))->toBeLessThan(250)
        ->and(abs($level - 2250))->toBeGreaterThan(50)
        ->and($recommendation->kcal)->toEqualWithDelta($level, 1e-6)
        ->and($recommendation->reason)->toEqual(RecommendationReason::towardTargetRate());
});

it('REV-023 caps a cut at 250 kcal', function () {
    $scenario = recFormulaScenario(3000);
    expect(recFormulaLevel($scenario))->toBeLessThan(2750);
    $recommendation = $scenario->recommend();
    expect($recommendation->kcal)->toEqualWithDelta(2750, 1e-9)
        ->and($recommendation->changeKcal)->toEqualWithDelta(-250, 1e-9)
        ->and($recommendation->reason)->toEqual(RecommendationReason::towardTargetRate());
});

it('REV-023 caps a raise at 250 kcal', function () {
    $scenario = recFormulaScenario(1500);
    expect(recFormulaLevel($scenario))->toBeGreaterThan(1750);
    $recommendation = $scenario->recommend();
    expect($recommendation->kcal)->toEqualWithDelta(1750, 1e-9)
        ->and($recommendation->changeKcal)->toEqualWithDelta(250, 1e-9);
});

it('REV-023 under 50 kcal is no change and 50 is a change', function () {
    $level = recFormulaLevel(recFormulaScenario());

    $small = recFormulaScenario($level + 49.9);
    $held = $small->recommend();
    expect($held->kcal)->toEqualWithDelta($level + 49.9, 1e-9)
        ->and($held->changeKcal)->toEqualWithDelta(0, 1e-9)
        ->and($held->reason)->toEqual(RecommendationReason::changeTooSmall());

    $enough = recFormulaScenario($level + 50.1);
    $moved = $enough->recommend();
    expect($moved->kcal)->toEqualWithDelta($level, 1e-6)
        ->and($moved->reason)->toEqual(RecommendationReason::towardTargetRate());
});

it('REV-023 exactly 50 kcal is a change', function () {
    // A zero target rate makes the level exactly the formula (2,600), so the change is exactly 50 both ways.
    foreach ([[2650.0, -50.0], [2550.0, 50.0]] as [$kcal, $change]) {
        $scenario = recFormulaScenario($kcal);
        $scenario->targetRate = 0;
        $recommendation = $scenario->recommend();
        expect($recommendation->changeKcal)->toBe($change)
            ->and($recommendation->kcal)->toBe(2600.0)
            ->and($recommendation->reason)->toEqual(RecommendationReason::towardTargetRate());
    }
});

it('REV-023 the week after a diet break goes straight back with no cap', function () {
    $scenario = new CalorieScenario(
        weeks: [
            F::week(0, intake: 2400, target: 2400),
            F::week(1, intake: 2300, target: 2300),
            F::week(2, intake: 3000, target: 3000, dietBreak: true),
        ],
        rates: [-0.5],
    );
    $recommendation = $scenario->recommend();
    expect($recommendation->kcal)->toEqualWithDelta(2300, 1e-9, 'the last non-break target')
        ->and($recommendation->changeKcal)->toEqualWithDelta(-700, 1e-9, 'past the 250 cap')
        ->and($recommendation->reason)->toEqual(RecommendationReason::returnFromBreak(), 'and no REV-026 water hold on this cut');
});

it('REV-023 the return skips deload weeks to the last normal target', function () {
    $scenario = new CalorieScenario(
        weeks: [
            F::week(0, intake: 2400, target: 2400),
            F::week(1, intake: 3000, target: 3000, deload: true),
            F::week(2, intake: 3000, target: 3000, dietBreak: true),
        ],
        rates: [-0.5],
    );
    $recommendation = $scenario->recommend();
    expect($recommendation->kcal)->toEqualWithDelta(2400, 1e-9)
        ->and($recommendation->reason)->toEqual(RecommendationReason::returnFromBreak());
});

it('REV-023 a deload week mid cut returns to the last target', function () {
    // PLAN-022: a fat loss deload runs at maintenance and is a diet break, even when the week is only marked
    // as a deload. The deficit has to come back afterwards.
    $scenario = new CalorieScenario(
        weeks: [
            F::week(0, intake: 2400, target: 2400),
            F::week(1, intake: 2300, target: 2300),
            F::week(2, intake: 3000, target: 3000, deload: true),
        ],
        rates: [-0.5],
    );
    $recommendation = $scenario->recommend();
    expect($recommendation->kcal)->toEqualWithDelta(2300, 1e-9, 'the last non-break target')
        ->and($recommendation->reason)->toEqual(RecommendationReason::returnFromBreak());
});

it('REV-023 a diet break with no earlier normal week is just the first week', function () {
    $scenario = new CalorieScenario(weeks: [F::week(0, dietBreak: true)], rates: [0]);
    expect($scenario->recommend()->reason)->toEqual(RecommendationReason::firstWeekOfPhase());
});

// REV-024

it('REV-024 a week on target keeps calories', function () {
    // The formula level would raise this week's 2,300 by far more than 50 kcal; on target it does not.
    $scenario = new CalorieScenario(weeks: F::weeks(4, 2300, false), rates: [-0.5], formulaKcal: 2900);
    $recommendation = $scenario->recommend();
    expect($scenario->level($recommendation) - 2300)->toBeGreaterThan(50)
        ->and($recommendation->reason)->toEqual(RecommendationReason::onTarget())
        ->and($recommendation->kcal)->toEqualWithDelta(2300, 1e-9)
        ->and($recommendation->changeKcal)->toEqualWithDelta(0, 1e-9);
});

// TBL-050

it('TBL-050 a fast target uses the relative band when it is wider', function () {
    // Target -1.0: 25% of it is 0.25, wider than the 0.15 absolute band.
    $inside = recFormulaScenario(2300, [-1, -1, -1, -0.78]);
    $inside->targetRate = -1;
    expect($inside->recommend()->reason)->toEqual(RecommendationReason::onTarget());

    $outside = recFormulaScenario(2300, [-1, -1, -1, -0.74]);
    $outside->targetRate = -1;
    expect($outside->recommend()->reason)->not->toEqual(RecommendationReason::onTarget());
});

it('TBL-050 a slow target uses the absolute band when it is wider', function () {
    // Target -0.3: 25% of it is 0.075, narrower than the 0.15 absolute band.
    $inside = recFormulaScenario(2300, [-0.3, -0.3, -0.3, -0.16]);
    $inside->targetRate = -0.3;
    expect($inside->recommend()->reason)->toEqual(RecommendationReason::onTarget());

    $outside = recFormulaScenario(2300, [-0.3, -0.3, -0.3, -0.14]);
    $outside->targetRate = -0.3;
    expect($outside->recommend()->reason)->not->toEqual(RecommendationReason::onTarget());
});

// REV-025

it('REV-025 fewer than five logged days repeats the week', function () {
    $weeks = F::weeks(4);
    $weeks[3] = F::week(3, logged: 4, intake: 2500, target: 2500);
    $scenario = new CalorieScenario(weeks: $weeks, rates: [-0.5, -0.5, -0.5, -0.1]);
    $recommendation = $scenario->recommend();
    expect($recommendation->reason)->toEqual(RecommendationReason::poorAdherence(4, 2500))
        ->and($recommendation->kcal)->toEqualWithDelta(2500, 1e-9);
});

it('REV-025 five logged days is enough', function () {
    $weeks = F::weeks(4);
    $weeks[3] = F::week(3, logged: 5, intake: 2500, target: 2500);
    $scenario = new CalorieScenario(weeks: $weeks, rates: [-0.5, -0.5, -0.5, -0.1]);
    expect($scenario->recommend()->reason->kind)->not->toBe(RecommendationReasonKind::PoorAdherence, '5 logged days is not poor adherence');
});

it('REV-025 nothing logged repeats the week', function () {
    $weeks = F::weeks(4);
    $weeks[3] = F::week(3, logged: 0, target: 2500);
    $scenario = new CalorieScenario(weeks: $weeks);
    expect($scenario->recommend()->reason)->toEqual(RecommendationReason::poorAdherence(0, null));
});

it('REV-025 average more than 10 percent off target repeats the week', function () {
    $reason = function (float $intake) {
        $weeks = F::weeks(4);
        $weeks[3] = F::week(3, intake: $intake, target: 2500);
        $scenario = new CalorieScenario(weeks: $weeks, rates: [-0.5, -0.5, -0.5, -0.1]);

        return $scenario->recommend()->reason;
    };
    expect($reason(2800))->toEqual(RecommendationReason::poorAdherence(7, 2800))
        ->and($reason(2200))->toEqual(RecommendationReason::poorAdherence(7, 2200));
    // The 10% boundary is 250 kcal either side.
    foreach ([2750.1, 2249.9] as $intake) {
        $outside = $reason($intake);
        expect($outside->kind)->toBe(RecommendationReasonKind::PoorAdherence, "{$intake} is more than 10% off")
            ->and($outside->loggedDays)->toBe(7);
    }
    foreach ([2749.9, 2250.1] as $intake) {
        expect($reason($intake)->kind)->not->toBe(RecommendationReasonKind::PoorAdherence, "{$intake} is within 10%");
    }
});

// REV-032

it('REV-032 the first week of a phase holds calories', function () {
    $scenario = new CalorieScenario(weeks: [F::week(0, intake: 2500, target: 2500)], rates: [-0.5]);
    $recommendation = $scenario->recommend();
    expect($recommendation->reason)->toEqual(RecommendationReason::firstWeekOfPhase())
        ->and($recommendation->kcal)->toEqualWithDelta(2500, 1e-9);
});

it('REV-032 the first week is the one that starts with the phase', function () {
    // Phase starts on day 21: week 3 is its first week, however far off target the trend is.
    $first = recFormulaScenario(3000);
    $first->phaseStartDay = 21;
    expect($first->recommend()->reason)->toEqual(RecommendationReason::firstWeekOfPhase());

    $second = new CalorieScenario(
        weeks: F::weeks(5, 3000, false), phaseStartDay: 21, rates: [-0.5, -0.5, -0.5, -0.5, -0.2],
    );
    expect($second->recommend()->reason)->not->toEqual(RecommendationReason::firstWeekOfPhase(), "week 4 is the phase's second week");
});

it('REV-032 the first review with poor adherence says so', function () {
    $scenario = new CalorieScenario(weeks: [F::week(0, logged: 3, target: 2500)], rates: [-0.5]);
    expect($scenario->recommend()->reason)->toEqual(RecommendationReason::poorAdherence(3, 2500));
});

it('REV-032 the first week is left out of stall detection', function () {
    // Rates -0.05 in weeks 2 and 3. With the phase starting on day 7 both are real weeks: stalled.
    // With it starting on day 14, week 2 is the first week and is excluded: not stalled.
    $reason = function (int $phaseStartDay) {
        $scenario = new CalorieScenario(
            weeks: F::weeks(4, 2300, false), phaseStartDay: $phaseStartDay, rates: [-0.5, -0.5, -0.05, -0.05],
        );

        return $scenario->recommend()->reason;
    };
    expect($reason(7)->kind)->toBe(RecommendationReasonKind::Stalled, 'expected a stall')
        ->and($reason(14)->kind)->not->toBe(RecommendationReasonKind::Stalled, 'the first week must not count towards a stall')
        ->and($reason(14))->toEqual(RecommendationReason::towardTargetRate());
});

it('REV-031 asking for a recommendation changes nothing', function () {
    $scenario = new CalorieScenario(weeks: F::weeks(4, 3000, false), rates: [-0.5, -0.5, -0.5, -0.2]);
    $first = $scenario->recommend();
    expect($scenario->recommend())->toEqual($first, 'a pure value: asking twice gives the same answer')
        ->and($first->kcal)->not->toBe(3000.0);
});

// REV-046 and SCH-064 have no Swift: they are bb's own.

/** The week with the SCH-064 and REV-046 marks set. */
function recMarked(ReviewWeek $week, bool $rough = false, bool $fatigued = false): ReviewWeek
{
    return new ReviewWeek(
        week: $week->week, loggedIntakeKcal: $week->loggedIntakeKcal, targetKcal: $week->targetKcal,
        hadHigherCarbDayShift: $week->hadHigherCarbDayShift, isRough: $rough, trainingFatigueHigh: $fatigued,
    );
}

/** Four weeks at 1,800 kcal on the formula's 2,600; the last loses 1.2% a week, past the band. */
function recLosingFast(bool $rough = false, bool $fatigued = true): CalorieScenario
{
    $weeks = F::weeks(4, 1800, false);
    $weeks[3] = recMarked($weeks[3], $rough, $fatigued);

    return new CalorieScenario(weeks: $weeks, rates: [-0.5, -0.5, -0.5, -1.2]);
}

it('REV-046 losing faster than the band with high training fatigue goes back to the target rate in one step', function () {
    $scenario = recLosingFast();
    $recommendation = $scenario->recommend();

    expect($recommendation->reason)->toEqual(RecommendationReason::losingTooFast())
        ->and($recommendation->kcal)->toEqualWithDelta(recFormulaLevel($scenario), 1e-6)
        ->and($recommendation->changeKcal)->toBeGreaterThan(250);
});

it('REV-046 REV-023 without high training fatigue the same week moves by at most 250 kcal', function () {
    $recommendation = recLosingFast(fatigued: false)->recommend();

    expect($recommendation->reason)->toEqual(RecommendationReason::towardTargetRate())
        ->and($recommendation->kcal)->toEqualWithDelta(2050, 1e-6);
});

/**
 * Three weeks at 1,800 kcal, too thinly logged to measure, the third losing 1.2% a week; then a diet break at 2,700,
 * NUT-002's maintenance as it was stored for the break. $third changes the third week.
 *
 * @param  Closure(ReviewWeek): ReviewWeek  $third
 */
function recBreakAfterFastWeek(Closure $third): CalorieScenario
{
    $weeks = F::weeks(3, 1800, false);
    $weeks[2] = $third($weeks[2]);
    $weeks[] = F::week(3, intake: 2700, target: 2700, dietBreak: true);

    return new CalorieScenario(weeks: $weeks, rates: [-0.5, -0.5, -1.2, -0.5]);
}

it('REV-046 the week after a break returns to the corrected target of the week that lost too fast', function () {
    $scenario = fn (bool $fatigued): CalorieScenario => recBreakAfterFastWeek(fn (ReviewWeek $week) => recMarked($week, fatigued: $fatigued));
    $fast = $scenario(true);
    // Too few weeks to measure up to the fast week: the expenditure REV-046 used then was the formula maintenance
    // built for the week after it, which is the break week, stored at 2,700 (not the 2,600 the formula gives now).
    $corrected = 2700 + F::kcal(-0.5, $fast->trend()->trendKg(F::day(20)));

    expect($fast->recommend()->reason)->toEqual(RecommendationReason::returnFromBreak())
        ->and($fast->recommend()->kcal)->toEqualWithDelta($corrected, 1e-6)
        ->and($corrected)->toBeGreaterThan(1800 + 250)
        ->and($scenario(false)->recommend()->kcal)->toEqualWithDelta(1800, 1e-9);
});

it('SCH-064 a rough week repeats the week, over REV-046', function () {
    $recommendation = recLosingFast(rough: true)->recommend();

    expect($recommendation->reason)->toEqual(RecommendationReason::roughWeek())
        ->and($recommendation->kcal)->toEqualWithDelta(1800, 1e-9);
});

it('SCH-064 REV-030 a rough week still meets the SAFE-011 check first', function () {
    $scenario = recLosingFast(rough: true);
    $scenario->heightCm = 220;

    expect($scenario->recommend()->reason->kind)->toBe(RecommendationReasonKind::Safety);
});

it('REV-046 a fast week that measured expenditure puts below its target falls through to the ordinary rules', function () {
    // Two slow eligible weeks, then a fast one: the weighted expenditure puts the target-rate level under 2,000.
    $scenario = function (bool $fatigued): CalorieScenario {
        $weeks = F::weeks(4, 2000);
        $weeks[3] = recMarked($weeks[3], fatigued: $fatigued);

        return new CalorieScenario(weeks: $weeks, rates: [-0.1, -0.1, -0.1, -0.8]);
    };
    $fast = $scenario(true);
    $recommendation = $fast->recommend();

    expect(F::isMeasured($recommendation->expenditure))->toBeTrue()
        ->and($fast->level($recommendation))->toBeLessThan(2000)
        ->and($recommendation->reason->kind)->not->toBe(RecommendationReasonKind::LosingTooFast)
        ->and($recommendation)->toEqual($scenario(false)->recommend());
});

it('REV-046 on the formula a fast week whose level is not above its target falls through to the ordinary rules', function () {
    $scenario = function (bool $fatigued): CalorieScenario {
        $weeks = F::weeks(4, 2250, false);
        $weeks[3] = recMarked($weeks[3], fatigued: $fatigued);

        return new CalorieScenario(weeks: $weeks, rates: [-0.5, -0.5, -0.5, -1.2]);
    };
    $fast = $scenario(true);
    $recommendation = $fast->recommend();

    expect($recommendation->expenditure)->toEqual(StartingCalories::formula(2600))
        ->and($fast->level($recommendation))->toBeLessThanOrEqual(2250)
        ->and($recommendation->reason->kind)->not->toBe(RecommendationReasonKind::LosingTooFast)
        ->and($recommendation)->toEqual($scenario(false)->recommend());
});

it('SCH-064 REV-046 a rough week that lost too fast is not corrected after the break that follows', function () {
    $recommendation = recBreakAfterFastWeek(fn (ReviewWeek $week) => recMarked($week, rough: true, fatigued: true))->recommend();

    expect($recommendation->reason)->toEqual(RecommendationReason::returnFromBreak())
        ->and($recommendation->kcal)->toEqualWithDelta(1800, 1e-9);
});

it('REV-046 REV-025 a poorly logged week that lost too fast is not corrected after the break that follows', function () {
    $thin = fn (ReviewWeek $week) => recMarked(F::week(2, logged: 4, intake: 1800, target: 1800), fatigued: true);
    $offTarget = fn (ReviewWeek $week) => recMarked(F::week(2, intake: 2100, target: 1800), fatigued: true);

    foreach ([$thin, $offTarget] as $third) {
        $recommendation = recBreakAfterFastWeek($third)->recommend();
        expect($recommendation->reason)->toEqual(RecommendationReason::returnFromBreak())
            ->and($recommendation->kcal)->toEqualWithDelta(1800, 1e-9);
    }
});

it('REV-046 a fast week whose corrected level is not above its target is not corrected after the break', function () {
    // At 2,400 the level from the break's 2,700 (about 2,270) would be a cut.
    $scenario = recBreakAfterFastWeek(fn (ReviewWeek $week) => recMarked(F::week(2, intake: 2400, target: 2400), fatigued: true));
    $recommendation = $scenario->recommend();

    expect(2700 + F::kcal(-0.5, $scenario->trend()->trendKg(F::day(20))))->toBeLessThan(2400)
        ->and($recommendation->kcal)->toEqualWithDelta(2400, 1e-9);
});

it('SCH-064 REV-030 a rough break week whose repeat breaks the macro floors holds this week', function () {
    // Repeat is the last ordinary week's 900 kcal: a 1,700 kcal cut from the break's 2,600 that SAFE-012 refuses.
    $weeks = [F::week(0, intake: 900, target: 900), recMarked(F::week(1, intake: 2600, target: 2600, dietBreak: true), rough: true)];
    $recommendation = (new CalorieScenario(weeks: $weeks, rates: [-0.5]))->recommend();

    expect($recommendation->reason->kind)->toBe(RecommendationReasonKind::Safety)
        ->and($recommendation->kcal)->toEqualWithDelta(2600, 1e-9);
});
