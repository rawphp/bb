<?php

use App\Domain\Macros\MacroRules;
use App\Domain\Review\CalorieReview;
use App\Domain\Review\DropFatigueReason;
use App\Domain\Review\FatigueAdvice;
use App\Domain\Review\MeasuredExpenditure;
use App\Domain\Review\RecommendationReasonKind;
use App\Domain\WeekBuilder\BuiltWeek;
use App\Models\PlanWeek;
use App\Models\User;
use App\Support\Review\ReviewAnalysis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\ReviewSeed;

// SCH-064 (the review of a rough week) and REV-046 (losing too fast with the lifts paying for it), on ReviewSeed's
// world: the week reviewed is 5 to 11 October 2026, reviewed on Monday 12 October, in fat loss at -0.5% a week.

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow(ReviewSeed::NOW));

/** 1% a week, twice the plan's rate and outside its band (TBL-050). */
function losingFast(ReviewSeed $seed): void
{
    $seed->weight = fn (int $offset): float => 90 - 0.1286 * ($offset + 7 * $seed->weeksFinished);
}

/** The analysis of a user's review, before and after the week reviewed is marked rough. */
function unmarkedAndRough(ReviewSeed $seed): array
{
    $user = $seed->seed();
    $unmarked = ReviewAnalysis::for($user, ReviewSeed::TODAY);
    markRough($user);

    return [$unmarked, ReviewAnalysis::for($user, ReviewSeed::TODAY)];
}

function markRough(User $user, string $start = '2026-10-05'): void
{
    PlanWeek::factory()->for($user)->create(['start_date' => $start, 'is_rough' => true]);
}

/** @return list<string> */
function builtDates(?BuiltWeek $week): array
{
    return $week === null ? [] : array_map(fn ($day) => $day->day->date->format('Y-m-d'), $week->days);
}

// MARK: SCH-064

it('SCH-064 the review of a rough week recommends Repeat this week, saying why', function () {
    // Off target (0.2% a week): unmarked, the week would move calories by 250.
    $seed = new ReviewSeed;
    $seed->weight = fn (int $offset): float => 90 - 0.0257 * ($offset + 28);
    [$unmarked, $rough] = unmarkedAndRough($seed);

    expect($unmarked->recommendation->kcal)->toEqualWithDelta(2150, 1e-6)
        ->and($rough->weeks[3]->isRough)->toBeTrue()
        ->and($rough->recommendation->reason->kind)->toBe(RecommendationReasonKind::RoughWeek)
        ->and($rough->recommendation->kcal)->toEqualWithDelta($rough->repeatKcal, 1e-6)
        ->and($rough->recommendation->kcal)->toEqualWithDelta(2400, 1e-6)
        ->and(ReviewSeed::reasonText($rough))->toBe('Rough week. Holding training and calories.');
});

it('SCH-064 REV-041 a rough week repeats even when training fatigue is high, and Drop fatigue is still advised', function () {
    $seed = new ReviewSeed;
    $seed->muscleFeedback = ReviewSeed::highFatigue(ReviewSeed::sessionKey(1));
    [$unmarked, $rough] = unmarkedAndRough($seed);

    expect($rough->recommendation->reason->kind)->toBe(RecommendationReasonKind::RoughWeek)
        ->and($rough->recommendation->kcal)->toEqualWithDelta(2400, 1e-6)
        ->and($rough->fatigue)->toEqual(FatigueAdvice::dropFatigue(DropFatigueReason::TrainingFatigue))
        ->and($rough->fatigue)->toEqual($unmarked->fatigue);
});

it('SCH-064 REV-046 a rough week repeats even when the week lost too fast with the lifts paying for it', function () {
    $seed = new ReviewSeed;
    losingFast($seed);
    $seed->muscleFeedback = ReviewSeed::highFatigue(ReviewSeed::sessionKey(1));
    [$unmarked, $rough] = unmarkedAndRough($seed);

    expect($unmarked->recommendation->reason->kind)->toBe(RecommendationReasonKind::LosingTooFast)
        ->and($rough->recommendation->reason->kind)->toBe(RecommendationReasonKind::RoughWeek)
        ->and($rough->recommendation->kcal)->toEqualWithDelta(2400, 1e-6);
});

it('SCH-064 REV-023 a rough diet break repeats the last ordinary week, not the break\'s target', function () {
    $seed = new ReviewSeed;
    $seed->planWeekRows = [['start_date' => '2026-10-05', 'is_diet_break' => true, 'is_rough' => true]];
    $seed->reviewedWeekShiftKcal = 300;
    $analysis = $seed->analysis();

    expect($analysis->weeks[3]->targetKcal)->toEqualWithDelta(2700, 1e-6)
        ->and($analysis->weeks[3]->isRough)->toBeTrue()
        ->and($analysis->recommendation->reason->kind)->toBe(RecommendationReasonKind::RoughWeek)
        ->and($analysis->repeatKcal)->toEqualWithDelta(2400, 1e-6)
        ->and($analysis->recommendation->kcal)->toEqualWithDelta(2400, 1e-6);
});

it('SCH-064 REV-030 the SAFE-011 check still runs first in a rough week', function () {
    $seed = new ReviewSeed;
    $seed->heightCm = 230;
    [, $rough] = unmarkedAndRough($seed);

    expect($rough->recommendation->reason->kind)->toBe(RecommendationReasonKind::Safety);
});

it('SCH-064 a rough week keeps the other calorie options', function () {
    [$unmarked, $rough] = unmarkedAndRough(new ReviewSeed);

    expect($rough->options)->toEqual($unmarked->options)
        ->and($rough->options->kinds)->toHaveCount(4);
});

it('SCH-064 REV-021 REV-040 a rough week still counts toward measured expenditure and fatigue', function () {
    $seed = new ReviewSeed;
    $seed->muscleFeedback = ReviewSeed::highFatigue(ReviewSeed::sessionKey(1));
    [$unmarked, $rough] = unmarkedAndRough($seed);
    $trend = $seed->trend();

    expect(MeasuredExpenditure::isEligible($rough->weeks[3], $rough->weeks[2]))->toBeTrue()
        ->and(MeasuredExpenditure::estimate($rough->weeks, $trend))->not->toBeNull()
        ->and(MeasuredExpenditure::estimate($rough->weeks, $trend))->toEqual(MeasuredExpenditure::estimate($unmarked->weeks, $trend))
        ->and($rough->recommendation->expenditure)->toEqual($unmarked->recommendation->expenditure)
        ->and($rough->weeks[3]->trainingFatigueHigh)->toBeTrue()
        ->and($rough->fatigue)->toEqual($unmarked->fatigue)
        ->and($rough->weeksSinceBreak)->toBe($unmarked->weeksSinceBreak);
});

it('SCH-064 a rough week moves no dates', function () {
    $seed = new ReviewSeed;
    $seed->mesocycleWeeks = 7;
    [$unmarked, $rough] = unmarkedAndRough($seed);

    expect($rough->deload)->toEqual($unmarked->deload)
        ->and($rough->deloadMesocycleID)->not->toBeNull()
        ->and($rough->deloadMesocycleID)->toBe($unmarked->deloadMesocycleID)
        ->and(builtDates($rough->plannedNextWeek))->toHaveCount(7)
        ->and(builtDates($rough->plannedNextWeek))->toBe(builtDates($unmarked->plannedNextWeek))
        ->and($rough->plannedNextWeek->week)->toEqual($unmarked->plannedNextWeek->week)
        ->and($rough->reviewed)->toEqual($unmarked->reviewed);
});

// MARK: REV-046

it('REV-046 losing faster than planned with high training fatigue returns calories to the target rate in one step', function () {
    $seed = new ReviewSeed;
    losingFast($seed);
    $seed->muscleFeedback = ReviewSeed::highFatigue(ReviewSeed::sessionKey(1));
    $analysis = $seed->analysis();
    $trend = $seed->trend();
    $level = MeasuredExpenditure::estimate($analysis->weeks, $trend)
        + MacroRules::dailyKcal(-0.5, $trend->trendKg(new DateTimeImmutable('2026-10-11', new DateTimeZone('UTC'))));

    expect($analysis->recommendation->reason->kind)->toBe(RecommendationReasonKind::LosingTooFast)
        ->and($analysis->recommendation->kcal)->toEqualWithDelta($level, 1e-6)
        ->and($analysis->recommendation->changeKcal)->toBeGreaterThan(CalorieReview::MAX_CHANGE_KCAL)
        ->and(ReviewSeed::reasonText($analysis))->toBe('You\'re losing faster than planned and your lifts are paying for it.');
});

it('REV-046 REV-041 Drop fatigue stays the fatigue advice beside the corrected target', function () {
    $seed = new ReviewSeed;
    losingFast($seed);
    $seed->muscleFeedback = ReviewSeed::highFatigue(ReviewSeed::sessionKey(1));
    $analysis = $seed->analysis();

    expect($analysis->fatigue)->toEqual(FatigueAdvice::dropFatigue(DropFatigueReason::TrainingFatigue))
        ->and($analysis->recommendation->reason->kind)->toBe(RecommendationReasonKind::LosingTooFast);
});

it('REV-046 REV-023 losing too fast without high training fatigue moves by at most 250 kcal', function () {
    $seed = new ReviewSeed;
    losingFast($seed);
    $analysis = $seed->analysis();

    expect($analysis->recommendation->reason->kind)->toBe(RecommendationReasonKind::TowardTargetRate)
        ->and($analysis->recommendation->kcal)->toEqualWithDelta(2400 + CalorieReview::MAX_CHANGE_KCAL, 1e-6);
});

it('REV-046 the week after the break returns to the corrected target, not the last ordinary one', function () {
    // Plan weeks 0 to 4 from 7 September: the week of 28 September lost too fast with the lifts paying for it, then
    // the week reviewed was a diet break at maintenance.
    $fast = function (bool $fatigued): ReviewSeed {
        $seed = new ReviewSeed;
        $seed->weeksFinished = 5;
        $seed->mesocycleWeeks = 8;
        losingFast($seed);
        $seed->planWeekRows = [['start_date' => '2026-10-05', 'is_diet_break' => true]];
        $seed->reviewedWeekShiftKcal = 300;
        $seed->earlierSessionDates = ['2026-09-28'];
        $seed->muscleFeedback = $fatigued ? ReviewSeed::highFatigue(ReviewSeed::earlierKey(0)) : [];

        return $seed;
    };
    $seed = $fast(true);
    $analysis = $seed->analysis();
    $plain = $fast(false)->analysis();
    $trend = $seed->trend();
    $corrected = MeasuredExpenditure::estimate(array_slice($analysis->weeks, 0, 4), $trend)
        + MacroRules::dailyKcal(-0.5, $trend->trendKg(new DateTimeImmutable('2026-10-04', new DateTimeZone('UTC'))));

    expect($analysis->weeks[3]->trainingFatigueHigh)->toBeTrue()
        ->and($analysis->weeks[3]->targetKcal)->toEqualWithDelta(2400, 1e-6)
        ->and($analysis->recommendation->reason->kind)->toBe(RecommendationReasonKind::ReturnFromBreak)
        ->and($analysis->recommendation->kcal)->toEqualWithDelta($corrected, 1e-6)
        ->and($analysis->recommendation->kcal)->toBeGreaterThan(2400 + CalorieReview::MAX_CHANGE_KCAL)
        ->and($plain->recommendation->reason->kind)->toBe(RecommendationReasonKind::ReturnFromBreak)
        ->and($plain->recommendation->kcal)->toEqualWithDelta(2400, 1e-6);
});

/** The target-rate level REV-046 would move the reviewed week to, from the analysis' expenditure (REV-023). */
function fastWeekLevel(ReviewAnalysis $analysis, ReviewSeed $seed): float
{
    return $analysis->recommendation->expenditure->kcal
        + MacroRules::dailyKcal(-0.5, $seed->trend()->trendKg(new DateTimeImmutable('2026-10-11', new DateTimeZone('UTC'))));
}

it('REV-046 REV-025 a poorly eaten week that lost too fast with the lifts paying for it is poor adherence', function () {
    // The week's target is cut to 1,800, so REV-046's level would be a raise, were it to decide the week.
    $seed = new ReviewSeed;
    losingFast($seed);
    $seed->reviewedWeekShiftKcal = -600;
    $seed->eatenShare = 0.8;
    $seed->muscleFeedback = ReviewSeed::highFatigue(ReviewSeed::sessionKey(1));
    $analysis = $seed->analysis();

    expect($analysis->weeks[3]->trainingFatigueHigh)->toBeTrue()
        ->and(fastWeekLevel($analysis, $seed))->toBeGreaterThan($analysis->weeks[3]->targetKcal)
        ->and($analysis->recommendation->reason->kind)->toBe(RecommendationReasonKind::PoorAdherence);
});

it('REV-046 REV-032 the phase\'s first week that lost too fast with the lifts paying for it holds as the first week', function () {
    $seed = new ReviewSeed;
    $seed->weeksFinished = 1;
    // Steeper than losingFast(): one week of trend lags the readings.
    $seed->weight = fn (int $offset): float => 90 - 0.2 * ($offset + 7);
    $seed->reviewedWeekShiftKcal = -600;
    $seed->muscleFeedback = ReviewSeed::highFatigue(ReviewSeed::sessionKey(1));
    $analysis = $seed->analysis();
    $rate = $seed->trend()->weeklyRatePercent(new DateTimeImmutable('2026-10-11', new DateTimeZone('UTC')));

    expect($analysis->weeks)->toHaveCount(1)
        ->and($analysis->weeks[0]->trainingFatigueHigh)->toBeTrue()
        ->and($rate)->toBeLessThan(-0.65)
        ->and(fastWeekLevel($analysis, $seed))->toBeGreaterThan($analysis->weeks[0]->targetKcal)
        ->and($analysis->recommendation->reason->kind)->toBe(RecommendationReasonKind::FirstWeekOfPhase);
});
