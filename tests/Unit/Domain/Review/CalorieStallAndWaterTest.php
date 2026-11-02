<?php

use App\Domain\Model\PhaseType;
use App\Domain\Review\CalorieRecommendation;
use App\Domain\Review\RecommendationReason;
use App\Domain\Review\RecommendationReasonKind;
use App\Domain\Review\StallChange;
use App\Domain\Review\StallOptions;
use PHPUnit\Framework\Assert;
use Tests\Unit\Domain\Review\CalorieFixtures as F;
use Tests\Unit\Domain\Review\CalorieScenario;

// The same tests as rp-clone's CalorieStallAndWaterTests (pin 2aa1edeb), one for one.

/**
 * Fat loss at -0.5%/week; weeks 2 and 3 move only -0.05%, under 25% of the target: a stall.
 * The formula (2,600) is the expenditure, so the level is 2,600 minus about 435.
 */
function stallScenario(float $kcal = 2300, int $steps = 6000): CalorieScenario
{
    return new CalorieScenario(
        weeks: F::weeks(4, $kcal, false), rates: [-0.5, -0.5, -0.05, -0.05], plannedSteps: $steps,
    );
}

function stallLevel(CalorieScenario $scenario): float
{
    return $scenario->formulaKcal + F::kcal(-0.5, $scenario->trendKg());
}

/** Fat loss with a -0.2% last week: off target, not a stall, and a cut is due. */
function cutDue(float $kcal = 3000): CalorieScenario
{
    return new CalorieScenario(weeks: F::weeks(4, $kcal, false), rates: [-0.5, -0.5, -0.5, -0.2]);
}

/** @param  list<float>  $rates */
function gainScenario(float $kcal, array $rates, float $formula = 3000): CalorieScenario
{
    return new CalorieScenario(
        weeks: F::weeks(4, $kcal, false), phaseType: PhaseType::MuscleGain, targetRate: 0.25, rates: $rates,
        formulaKcal: $formula,
    );
}

function stallOptions(CalorieRecommendation $recommendation): StallOptions
{
    Assert::assertSame(
        RecommendationReasonKind::Stalled, $recommendation->reason->kind,
        "expected a stall, got {$recommendation->reason->kind->name}",
    );

    return $recommendation->reason->stall;
}

// REV-026

it('REV-026 without water a cut goes ahead', function () {
    $recommendation = cutDue()->recommend();
    expect($recommendation->reason)->toEqual(RecommendationReason::towardTargetRate())
        ->and($recommendation->kcal)->toEqualWithDelta(2750, 1e-9);
});

it('REV-026 a deload week holds the cut', function () {
    // A deload during fat loss is a break to maintenance (PLAN-022), so it returns to the last target instead
    // (see the REV-023 deload test). Outside fat loss the hold applies.
    $scenario = cutDue();
    $scenario->phaseType = PhaseType::Maintenance;
    $scenario->targetRate = 0;
    $scenario->rates = [0.5];
    $scenario->weeks[3] = F::marked($scenario->weeks[3], deload: true);
    $recommendation = $scenario->recommend();
    expect($recommendation->reason)->toEqual(RecommendationReason::waterWeight())
        ->and($recommendation->kcal)->toEqualWithDelta(3000, 1e-9)
        ->and($recommendation->changeKcal)->toEqualWithDelta(0, 1e-9);
});

it('REV-026 a higher carb day shift holds the cut', function () {
    $scenario = cutDue();
    $scenario->weeks[3] = F::marked($scenario->weeks[3], higherCarbShift: true);
    expect($scenario->recommend()->reason)->toEqual(RecommendationReason::waterWeight());
});

it('REV-026 a jump over one percent that reverses within three days holds the cut', function () {
    $scenario = cutDue();
    $scenario->readingFactors = [24 => 1.02]; // one reading 2% up, the next days back to normal
    $recommendation = $scenario->recommend();
    expect($recommendation->reason)->toEqual(RecommendationReason::waterWeight())
        ->and($recommendation->kcal)->toEqualWithDelta(3000, 1e-9);
});

it('REV-026 a jump that does not reverse is real weight and does not hold', function () {
    $scenario = cutDue();
    $scenario->readingFactors = [24 => 1.02, 25 => 1.02, 26 => 1.02, 27 => 1.02];
    $recommendation = $scenario->recommend();
    expect($recommendation->reason)->toEqual(RecommendationReason::towardTargetRate())
        ->and($recommendation->kcal)->toEqualWithDelta(2750, 1e-9);
});

it('REV-026 a jump under one percent does not hold', function () {
    $scenario = cutDue();
    $scenario->readingFactors = [24 => 1.005];
    expect($scenario->recommend()->reason)->toEqual(RecommendationReason::towardTargetRate());
});

it('REV-026 water only holds a cut never a raise', function () {
    $scenario = cutDue(1500);
    $scenario->weeks[3] = F::marked($scenario->weeks[3], higherCarbShift: true);
    $recommendation = $scenario->recommend();
    expect($recommendation->reason)->toEqual(RecommendationReason::towardTargetRate())
        ->and($recommendation->kcal)->toEqualWithDelta(1750, 1e-9);
});

it('REV-026 water holds instead of a stall cut', function () {
    $scenario = stallScenario();
    $scenario->weeks[3] = F::marked($scenario->weeks[3], higherCarbShift: true);
    $recommendation = $scenario->recommend();
    expect($recommendation->reason)->toEqual(RecommendationReason::waterWeight())
        ->and($recommendation->kcal)->toEqualWithDelta(2300, 1e-9);
});

it('REV-026 the water message is the spec copy', function () {
    expect(RecommendationReason::waterWeight()->message())->toBe('Water weight is up. Holding your target this week.')
        ->and(RecommendationReason::onTarget()->message())->toBeNull()
        ->and(RecommendationReason::towardTargetRate()->message())->toBeNull();
});

// REV-027

it('REV-027 a stall needs two reviews running', function () {
    $oneWeek = stallScenario();
    $oneWeek->rates = [-0.5, -0.5, -0.5, -0.05];
    expect($oneWeek->recommend()->reason)->toEqual(RecommendationReason::towardTargetRate())
        ->and(stallScenario()->recommend()->reason->kind)->toBe(RecommendationReasonKind::Stalled, 'two weeks of -0.05% is a stall');
});

it('REV-027 a break or away week before the review is not a stalled week', function () {
    $scenario = stallScenario();
    $scenario->weeks[2] = F::marked($scenario->weeks[2], away: true);
    expect($scenario->recommend()->reason)->toEqual(RecommendationReason::towardTargetRate());
});

it('REV-027 a mini cut stalls like fat loss', function () {
    $scenario = stallScenario();
    $scenario->phaseType = PhaseType::MiniCut;
    expect($scenario->recommend()->reason->kind)->toBe(RecommendationReasonKind::Stalled, 'PLAN-009: Mini-cut is Fat loss');
});

it('REV-027 the message is the spec copy', function () {
    $reason = stallScenario()->recommend()->reason;
    expect($reason->message())->toBe('Your progress has stalled');
});

it('REV-027 offers a calorie cut first and steps as the other option', function () {
    $scenario = stallScenario(2300);
    $recommendation = $scenario->recommend();
    $cut = 2300 - stallLevel($scenario);
    expect($cut)->toBeGreaterThan(50)
        ->and($cut)->toBeLessThan(250);

    $offered = stallOptions($recommendation);
    expect($offered->cutKcal)->toEqualWithDelta($cut, 1e-6)
        ->and($offered->addSteps)->toBe(2000)
        ->and($offered->recommended)->toBe(StallChange::CutCalories)
        ->and($recommendation->kcal)->toEqualWithDelta(2300 - $cut, 1e-6)
        ->and($recommendation->changeKcal)->toEqualWithDelta(-$cut, 1e-6);
});

it('REV-027 the cut is capped at 250', function () {
    $scenario = stallScenario(2600);
    expect(2600 - stallLevel($scenario))->toBeGreaterThan(250)
        ->and(stallOptions($scenario->recommend())->cutKcal)->toEqualWithDelta(250, 1e-9);
});

it('REV-027 recommends steps when the cut would reach the fat floor', function () {
    // At 2,450 kcal a 250 kcal cut needs more fat than sits above the fat floor.
    $recommendation = stallScenario(2450)->recommend();
    $offered = stallOptions($recommendation);
    expect($offered->cutKcal)->toEqualWithDelta(250, 1e-9, 'the cut is still offered')
        ->and($offered->addSteps)->toBe(2000)
        ->and($offered->recommended)->toBe(StallChange::AddSteps)
        ->and($recommendation->kcal)->toEqualWithDelta(2450, 1e-9, 'calories hold while steps go up');
});

it('REV-027 steps go up by 1000 to 2000 and stop at 10000', function () {
    expect(stallOptions(stallScenario(steps: 6000)->recommend())->addSteps)->toBe(2000)
        ->and(stallOptions(stallScenario(steps: 8000)->recommend())->addSteps)->toBe(2000)
        ->and(stallOptions(stallScenario(steps: 9000)->recommend())->addSteps)->toBe(1000)
        ->and(stallOptions(stallScenario(steps: 9500)->recommend())->addSteps)->toBeNull('under 1,000 of room')
        ->and(stallOptions(stallScenario(steps: 10000)->recommend())->addSteps)->toBeNull();
});

it('REV-027 with no room for steps the cut is recommended even near the fat floor', function () {
    $recommendation = stallScenario(2450, 9500)->recommend();
    $offered = stallOptions($recommendation);
    expect($offered->addSteps)->toBeNull()
        ->and($offered->recommended)->toBe(StallChange::CutCalories)
        ->and($recommendation->kcal)->toEqualWithDelta(2200, 1e-9);
});

it('REV-027 a cut that breaks the carb minimum is not offered', function () {
    // 1,300 kcal has fat at its floor and only about 55 g of carbs: SAFE-012 refuses any real cut.
    $recommendation = stallScenario(1300)->recommend();
    $offered = stallOptions($recommendation);
    expect($offered->cutKcal)->toBeNull()
        ->and($offered->addSteps)->toBe(2000)
        ->and($offered->recommended)->toBe(StallChange::AddSteps)
        ->and($recommendation->kcal)->toEqualWithDelta(1300, 1e-9);
});

it('REV-027 no cut and no steps holds', function () {
    $recommendation = stallScenario(1300, 9800)->recommend();
    $offered = stallOptions($recommendation);
    expect($offered->cutKcal)->toBeNull()
        ->and($offered->addSteps)->toBeNull()
        ->and($offered->recommended)->toBe(StallChange::Hold)
        ->and($recommendation->kcal)->toEqualWithDelta(1300, 1e-9);
});

it('REV-027 when the level is above this week the observed gap sizes the cut', function () {
    // Level is about 2,165, above this week's 2,150, yet progress has stalled: cut by the gap to the target
    // rate (-0.45% of bodyweight, about 390 kcal), capped at 250.
    $scenario = stallScenario(2150);
    expect(stallLevel($scenario))->toBeGreaterThan(2150)
        ->and(stallOptions($scenario->recommend())->cutKcal)->toEqualWithDelta(250, 1e-9);
});

// REV-028

it('REV-028 a stall in muscle gain recommends more calories', function () {
    $scenario = gainScenario(3000, [0.25, 0.25, 0, 0]);
    $recommendation = $scenario->recommend();
    $level = $scenario->level($recommendation);
    expect($level - 3000)->toBeGreaterThan(50)
        ->and($level - 3000)->toBeLessThan(250)
        ->and($recommendation->reason)->toEqual(RecommendationReason::gainStalled())
        ->and($recommendation->kcal)->toEqualWithDelta($level, 1e-6);
});

it('REV-028 a stall adds the observed gap when the level is below this week', function () {
    $scenario = gainScenario(3300, [0.25, 0.25, 0, 0]);
    $recommendation = $scenario->recommend();
    expect($scenario->level($recommendation))->toBeLessThan(3300)
        ->and($recommendation->reason)->toEqual(RecommendationReason::gainStalled())
        ->and($recommendation->kcal)->toEqualWithDelta(3300 + F::kcal(0.25, $scenario->trendKg()), 1e-6);
});

it('REV-028 gaining faster than the band recommends fewer calories', function () {
    $scenario = gainScenario(3300, [0.25, 0.25, 0.25, 0.6], 2900);
    $recommendation = $scenario->recommend();
    $level = $scenario->level($recommendation);
    expect($level - 3300)->toBeLessThan(-50)
        ->and($level - 3300)->toBeGreaterThan(-250)
        ->and($recommendation->reason)->toEqual(RecommendationReason::gainTooFast())
        ->and($recommendation->kcal)->toEqualWithDelta($level, 1e-6);
});

it('REV-028 too fast still cuts when the level is above this week capped at 250', function () {
    $scenario = gainScenario(3000, [0.25, 0.25, 0.25, 0.6], 2900);
    $recommendation = $scenario->recommend();
    expect($scenario->level($recommendation))->toBeGreaterThan(3000)
        ->and($recommendation->reason)->toEqual(RecommendationReason::gainTooFast())
        ->and($recommendation->kcal)->toEqualWithDelta(2750, 1e-9);
});

it('REV-028 water holds a gain cut', function () {
    $scenario = gainScenario(3000, [0.25, 0.25, 0.25, 0.6], 2900);
    $scenario->weeks[3] = F::marked($scenario->weeks[3], higherCarbShift: true);
    $recommendation = $scenario->recommend();
    expect($recommendation->reason)->toEqual(RecommendationReason::waterWeight())
        ->and($recommendation->kcal)->toEqualWithDelta(3000, 1e-9);
});

it('REV-028 on target gain keeps calories', function () {
    $recommendation = gainScenario(3000, [0.25])->recommend();
    expect($recommendation->reason)->toEqual(RecommendationReason::onTarget())
        ->and($recommendation->kcal)->toEqualWithDelta(3000, 1e-9);
});

it('REV-028 slow but not stalled gain moves toward the target rate', function () {
    $scenario = gainScenario(3000, [0.25, 0.25, 0.25, 0.08]);
    $recommendation = $scenario->recommend();
    expect($recommendation->reason)->toEqual(RecommendationReason::towardTargetRate())
        ->and($recommendation->kcal)->toEqualWithDelta($scenario->level($recommendation), 1e-6);
});
