<?php

use App\Domain\Phases\StartingCaloriesSource;
use App\Domain\Review\DropFatigueReason;
use App\Domain\Review\FatigueAdvice;
use App\Domain\Review\MeasuredExpenditure;
use App\Domain\Review\RecommendationReasonKind;
use App\Domain\Review\ReviewMode;
use App\Domain\Review\StallChange;
use App\Models\PlanWeek;
use App\Models\User;
use App\Support\Review\ReviewAnalysis;
use App\Support\Review\ReviewMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\ReviewSeed;

// The weekly review's analysis of stored rows (rp-clone ReviewAnalysis), with the recommendation, reason and fatigue
// cases of WeeklyReviewCaloriesTests. The world is ReviewSeed's: the week reviewed is 5 to 11 October 2026, reviewed
// on Monday 12 October; four plan weeks have finished on a fat-loss phase at -0.5% a week, eaten exactly on target.

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow(ReviewSeed::NOW));

/** @param  Closure(ReviewSeed): void  $change */
function reviewed(?Closure $change = null, string $today = ReviewSeed::TODAY): ReviewAnalysis
{
    $seed = new ReviewSeed;
    if ($change !== null) {
        $change($seed);
    }

    return $seed->analysis($today) ?? throw new RuntimeException('No review is open.');
}

// MARK: when the review is open

it('REV-002 the review stays open after the review day, and Repeat keeps the week that ended', function () {
    $analysis = reviewed(today: '2026-10-15');

    expect($analysis->period->reviewed->start->format('Y-m-d'))->toBe('2026-10-05')
        ->and($analysis->period->next->start->format('Y-m-d'))->toBe('2026-10-12')
        ->and($analysis->reviewed->start->format('Y-m-d'))->toBe('2026-10-05')
        ->and($analysis->repeatKcal)->toEqualWithDelta(2400, 1e-6)
        ->and($analysis->mode)->toBe(ReviewMode::Full);
});

it('REV-002 there is no review once it is done, before a week has finished, or without a plan', function () {
    $done = new ReviewSeed;
    $done->planWeekRows = [['start_date' => '2026-10-05', 'reviewed_at' => '2026-10-12 08:00:00']];
    $early = new ReviewSeed;

    expect($done->analysis())->toBeNull()
        ->and(ReviewAnalysis::for($early->seed(), $early->planStart()))->toBeNull()
        ->and(ReviewAnalysis::for(User::factory()->create(), ReviewSeed::TODAY))->toBeNull();
});

it('REV-002 there is no review while the week that ended has fewer than 2 weigh-ins', function () {
    $seed = new ReviewSeed;
    $seed->weight = fn (int $offset): ?float => $offset >= -7 && $offset <= -1 && $offset !== -3 ? null : 88.0;

    expect($seed->analysis())->toBeNull();
});

it('REV-005 a training-only profile reviews in training-only mode, with no calorie recommendation or fatigue advice', function () {
    $analysis = reviewed(function (ReviewSeed $seed) {
        $seed->trainingOnly = true;
        $seed->muscleFeedback = ReviewSeed::highFatigue(ReviewSeed::sessionKey(1));
    });

    expect($analysis->mode)->toBe(ReviewMode::TrainingOnly)
        ->and($analysis->recommendation)->toBeNull()
        ->and($analysis->fatigue)->toBeNull()
        ->and($analysis->training->sessionsDone)->toBe(3);
});

it('SCH-061 an away week is reviewed in away mode, with no calorie recommendation', function () {
    $analysis = reviewed(fn (ReviewSeed $seed) => $seed->planWeekRows = [
        ['start_date' => '2026-10-05', 'is_away' => true, 'away_training' => 'pause', 'away_calories' => 'maintenance'],
    ]);

    expect($analysis->mode)->toBe(ReviewMode::Away)
        ->and($analysis->reviewed->isAway)->toBeTrue()
        ->and($analysis->recommendation)->toBeNull();
});

// MARK: steps 1 to 4

it('REV-003 the analysis holds the week\'s weigh-ins, meals, training and progress', function () {
    $analysis = reviewed(function (ReviewSeed $seed) {
        $seed->sessionsDone = 2;
        $seed->sessionsSkipped = 1;
        $seed->isChecked = fn (string $date, int $meal) => ! ($date === '2026-10-07' && $meal === 2);
        $seed->exerciseFeedback = [['session' => ReviewSeed::sessionKey(1), 'exercise' => 'Bench Press', 'pain' => 'moderate']];
    });

    expect(array_map(fn ($row) => $row->day->format('Y-m-d'), $analysis->weighInRows))->toBe(array_map(fn (int $day) => sprintf('2026-10-%02d', $day), range(5, 11)))
        ->and($analysis->weighInRows[0]->isScheduled)->toBeTrue()
        ->and($analysis->meals->total())->toBe(28)
        ->and($analysis->meals->checkedIn())->toBe(27)
        ->and($analysis->meals->unchecked()[0]->name)->toBe('Meal 2')
        ->and($analysis->training->sessionsDone)->toBe(2)
        ->and($analysis->training->sessionsSkipped)->toBe(1)
        ->and($analysis->training->sessionsMissed)->toBe(0)
        ->and($analysis->training->setsPlanned)->toBe(20)
        ->and($analysis->training->setsDone)->toBe(14)
        ->and($analysis->training->jointPain[0]->exerciseName)->toBe('Bench Press')
        ->and($analysis->progress)->not->toBeNull()
        ->and($analysis->progress->rateIsOnTarget)->toBeTrue();
});

// MARK: the recommendation (REV-020 to REV-032)

it('REV-020 the week is read from the trend weight, not the last weigh-in', function () {
    // A low reading on the week's last day moves the trend a little, so the rate stays on target.
    $analysis = reviewed(fn (ReviewSeed $seed) => $seed->weight = fn (int $offset): float => $offset === -1 ? 87.5 : 90 - 0.0643 * ($offset + 28));

    expect($analysis->recommendation->reason->kind)->toBe(RecommendationReasonKind::OnTarget)
        ->and($analysis->progress->trendKg)->toBeGreaterThan(88.0);
});

it('REV-021 expenditure is measured once there are 3 eligible weeks, and the formula stands in until then', function () {
    $measured = reviewed();
    $early = reviewed(fn (ReviewSeed $seed) => $seed->weeksFinished = 2);

    expect(array_map(fn ($week) => $week->loggedDays(), $measured->weeks))->toBe([7, 7, 7, 7])
        ->and($measured->weeks[3]->averageIntakeKcal())->toEqualWithDelta(2400, 1e-6)
        ->and($measured->weeks[3]->targetKcal)->toEqualWithDelta(2400, 1e-6)
        ->and($measured->recommendation->expenditure->source)->toBe(StartingCaloriesSource::Measured)
        ->and($early->recommendation->expenditure->source)->toBe(StartingCaloriesSource::Formula);
});

it('REV-022 a diet break and the week after it are not eligible for measured expenditure', function () {
    $analysis = reviewed(fn (ReviewSeed $seed) => $seed->planWeekRows = [['start_date' => '2026-09-21', 'is_diet_break' => true]]);
    $weeks = $analysis->weeks;

    expect(MeasuredExpenditure::isEligible($weeks[1], $weeks[0]))->toBeFalse()
        ->and(MeasuredExpenditure::isEligible($weeks[2], $weeks[1]))->toBeFalse()
        ->and(MeasuredExpenditure::isEligible($weeks[3], $weeks[2]))->toBeTrue()
        ->and($analysis->recommendation->expenditure->source)->toBe(StartingCaloriesSource::Formula);
});

it('REV-023 an off-target rate moves calories toward it by at most 250 kcal and says so', function () {
    // 0.2% a week, slower than the target band and faster than a stall.
    $analysis = reviewed(fn (ReviewSeed $seed) => $seed->weight = fn (int $offset): float => 90 - 0.0257 * ($offset + 28));

    expect($analysis->recommendation->kcal)->toEqualWithDelta(2150, 1e-6)
        ->and($analysis->recommendation->reason->kind)->toBe(RecommendationReasonKind::TowardTargetRate)
        ->and(ReviewSeed::reasonText($analysis))->toBe('Your rate is off target, so calories move toward it.');
});

it('REV-023 the week after a diet break goes straight back to the last ordinary target, as Repeat does', function () {
    $analysis = reviewed(function (ReviewSeed $seed) {
        $seed->planWeekRows = [['start_date' => '2026-10-05', 'is_diet_break' => true]];
        $seed->reviewedWeekShiftKcal = 300;
    });

    expect($analysis->weeks[3]->targetKcal)->toEqualWithDelta(2700, 1e-6)
        ->and($analysis->recommendation->kcal)->toEqualWithDelta(2400, 1e-6)
        ->and($analysis->repeatKcal)->toEqualWithDelta(2400, 1e-6)
        ->and(ReviewSeed::reasonText($analysis))->toBe('After a break, calories go straight back to your last ordinary target.');
});

it('REV-024 a rate within the target band keeps calories and says so', function () {
    $analysis = reviewed();

    expect($analysis->recommendation->kcal)->toEqualWithDelta(2400, 1e-6)
        ->and(ReviewSeed::reasonText($analysis))->toBe('You are on target, so calories stay as they are.');
});

it('REV-010 REV-025 when adherence was poor the recommendation repeats the week and says what was eaten', function () {
    $analysis = reviewed(fn (ReviewSeed $seed) => $seed->eatenShare = 0.8); // 1,920 kcal against a target of 2,400

    expect($analysis->recommendation->kcal)->toEqualWithDelta(2400, 1e-6)
        ->and(ReviewSeed::reasonText($analysis))
        ->toBe('You averaged 1,920 kcal against a target of 2,400 kcal. Hit your targets first, then the app adjusts.');
});

it('REV-025 fewer than five logged days is poor adherence', function () {
    $analysis = reviewed(fn (ReviewSeed $seed) => $seed->isChecked = fn (string $date, int $meal) => $date < '2026-10-05' || $date === '2026-10-05' || $date === '2026-10-06');

    expect(ReviewSeed::reasonText($analysis))->toBe('You logged 2 of 7 days. Hit your targets first, then the app adjusts.');
});

it('REV-026 a trend that rose on water holds the target and says so', function () {
    // A jump of more than 1% on 6 October that is back by the 8th, on a trend that has not moved for weeks.
    $analysis = reviewed(fn (ReviewSeed $seed) => $seed->weight = fn (int $offset): float => $offset === -6 ? 89.6 : 88.0);

    expect($analysis->recommendation->kcal)->toEqualWithDelta(2400, 1e-6)
        ->and(ReviewSeed::reasonText($analysis))->toBe('Water weight is up. Holding your target this week.');
});

it('REV-027 a stall says so and recommends one change at a time', function () {
    $analysis = reviewed(fn (ReviewSeed $seed) => $seed->weight = fn (int $offset): float => 88.0);
    $stall = $analysis->recommendation->reason->stall;

    expect(ReviewSeed::reasonText($analysis))->toBe('Your progress has stalled')
        ->and($stall->cutKcal)->toEqualWithDelta(250, 1e-6)
        ->and($stall->addSteps)->toBe(2000)
        // 70 g of fat a day is close to the fat floor after a 250 kcal cut, so steps come first and calories hold.
        ->and($stall->recommended)->toBe(StallChange::AddSteps)
        ->and($analysis->recommendation->kcal)->toEqualWithDelta(2400, 1e-6);
});

it('REV-028 a stalled gain in Muscle gain raises calories and says so', function () {
    $analysis = reviewed(function (ReviewSeed $seed) {
        $seed->phaseType = 'muscleGain';
        $seed->ratePercent = 0.25;
        $seed->weight = fn (int $offset): float => 90.0;
    });

    expect(ReviewSeed::reasonText($analysis))->toBe('Your gain has stalled, so calories go up.')
        ->and($analysis->recommendation->kcal)->toBeGreaterThan(2400);
});

it('REV-029 a trend inside the maintenance corridor keeps calories and says so', function () {
    $analysis = reviewed(function (ReviewSeed $seed) {
        $seed->phaseType = 'maintenance';
        $seed->ratePercent = 0;
        $seed->weight = fn (int $offset): float => 90.0;
    });

    expect(ReviewSeed::reasonText($analysis))->toBe('Your trend weight is inside its corridor, so calories stay as they are.');
});

it('REV-030 at a BMI of 17 or less fat loss stops and calories rise to maintenance', function () {
    $analysis = reviewed(fn (ReviewSeed $seed) => $seed->heightCm = 230);

    expect($analysis->recommendation->reason->kind)->toBe(RecommendationReasonKind::Safety)
        ->and($analysis->recommendation->kcal)->toBeGreaterThanOrEqual(2400)
        ->and(ReviewSeed::reasonText($analysis))->toBe('Your BMI is too low to plan a cut, so calories rise to maintenance.');
});

it('REV-032 the first review of a phase holds calories and says so', function () {
    $analysis = reviewed(function (ReviewSeed $seed) {
        $seed->weeksFinished = 1;
        $seed->weight = fn (int $offset): float => 90.0;
    });

    expect($analysis->recommendation->kcal)->toEqualWithDelta(2400, 1e-6)
        ->and(ReviewSeed::reasonText($analysis))->toBe('The first week of a phase is left out of the rate, so calories hold.');
});

// MARK: fatigue (REV-040 to REV-044)

it('REV-040 a week\'s training fatigue counts its still-sore and too-much answers', function () {
    $calm = reviewed();
    $high = reviewed(fn (ReviewSeed $seed) => $seed->muscleFeedback = ReviewSeed::highFatigue(ReviewSeed::sessionKey(1)));

    expect($calm->weeks[3]->trainingFatigueHigh)->toBeFalse()
        ->and($high->weeks[3]->trainingFatigueHigh)->toBeTrue()
        ->and($high->weeks[2]->trainingFatigueHigh)->toBeFalse()
        ->and($calm->weeksSinceBreak)->toBe(4);
});

it('REV-041 high training fatigue recommends Drop fatigue with the spec\'s words', function () {
    $analysis = reviewed(fn (ReviewSeed $seed) => $seed->muscleFeedback = [
        ['session' => ReviewSeed::sessionKey(1), 'muscle' => 'chest', 'soreness' => 'stillSore', 'volume' => 'tooMuch'],
        ['session' => ReviewSeed::sessionKey(1), 'muscle' => 'back', 'soreness' => 'stillSore', 'volume' => 'justRight'],
        ['session' => ReviewSeed::sessionKey(1), 'muscle' => 'quads', 'soreness' => 'neverGotSore', 'volume' => 'justRight'],
    ]);

    expect($analysis->fatigue)->toEqual(FatigueAdvice::dropFatigue(DropFatigueReason::TrainingFatigue))
        ->and(ReviewMessages::fatigue($analysis->fatigue, $analysis->weeksSinceBreak))
        ->toBe('Your lifts are dropping. It might be the diet, not the training.');
});

it('REV-042 six weeks without a break suggests Drop fatigue without other signs', function () {
    $analysis = reviewed(function (ReviewSeed $seed) {
        $seed->weeksFinished = 6;
        $seed->mesocycleWeeks = 8;
    });

    expect($analysis->fatigue)->toEqual(FatigueAdvice::dropFatigue(DropFatigueReason::WeeksSinceBreak))
        ->and(ReviewMessages::fatigue($analysis->fatigue, $analysis->weeksSinceBreak))
        ->toBe('You have been in a deficit for 6 weeks without a diet break or deload.');
});

it('REV-043 fatigue that stays high after a diet break and a deload advises ending fat loss', function () {
    // Plan weeks 0 to 3 of the phase: high, a deload diet break, a diet break, high (the week reviewed).
    $analysis = reviewed(function (ReviewSeed $seed) {
        $seed->earlierSessionDates = ['2026-09-14'];
        $seed->planWeekRows = [
            ['start_date' => '2026-09-21', 'is_deload' => true, 'is_diet_break' => true],
            ['start_date' => '2026-09-28', 'is_diet_break' => true],
        ];
        $seed->muscleFeedback = [...ReviewSeed::highFatigue(ReviewSeed::earlierKey(0)), ...ReviewSeed::highFatigue(ReviewSeed::sessionKey(1))];
    });

    expect($analysis->fatigue)->toEqual(FatigueAdvice::endFatLoss())
        ->and(ReviewMessages::fatigue($analysis->fatigue, $analysis->weeksSinceBreak))
        ->toBe('Your training fatigue stayed high after a diet break and a deload. Consider ending fat loss and moving to Maintenance.');
});

it('REV-043 without fatigue that stays high there is no advice to end fat loss', function () {
    $calm = reviewed();
    $high = reviewed(fn (ReviewSeed $seed) => $seed->muscleFeedback = ReviewSeed::highFatigue(ReviewSeed::sessionKey(1)));

    expect($calm->fatigue)->toBeNull()
        ->and($high->fatigue)->toEqual(FatigueAdvice::dropFatigue(DropFatigueReason::TrainingFatigue));
});

it('REV-044 Muscle gain never turns fatigue into a diet change', function () {
    $analysis = reviewed(function (ReviewSeed $seed) {
        $seed->phaseType = 'muscleGain';
        $seed->ratePercent = 0.25;
        $seed->muscleFeedback = [['session' => ReviewSeed::sessionKey(1), 'muscle' => 'chest', 'soreness' => 'stillSore', 'volume' => 'tooMuch']];
    });

    expect($analysis->fatigue)->toBeNull();
});

// MARK: steps (REV-050)

it('REV-050 the review offers to update the step plan when the average differs by more than 2,000', function () {
    $analysis = reviewed(fn (ReviewSeed $seed) => $seed->steps = array_fill_keys(array_map(fn (int $d) => ReviewSeed::day($d), range(-7, -1)), 10000));

    expect($analysis->stepOffer->actualAverage)->toEqualWithDelta(10000, 1e-6)
        ->and($analysis->stepOffer->plannedAverage)->toEqualWithDelta(7000, 1e-6)
        ->and(ReviewMessages::steps($analysis->stepOffer))
        ->toBe('You walked 10,000 steps a day on average against a plan of 7,000. Update your step plan?');
});

it('REV-050 without step data or a clear gap there is no offer', function () {
    $none = reviewed();
    $near = reviewed(fn (ReviewSeed $seed) => $seed->steps = array_fill_keys(array_map(fn (int $d) => ReviewSeed::day($d), range(-7, -1)), 8500));
    // Steps outside the week reviewed are not its.
    $outside = reviewed(fn (ReviewSeed $seed) => $seed->steps = array_fill_keys(array_map(fn (int $d) => ReviewSeed::day($d), range(-14, -8)), 15000));

    expect($none->stepOffer)->toBeNull()
        ->and($near->stepOffer)->toBeNull()
        ->and($outside->stepOffer)->toBeNull();
});

// MARK: one user's rows

it('reads only the user\'s own rows, never another user\'s in the same week', function () {
    // Made first, so an unscoped query would find its rows: heavier, half eaten, fatigued, a rough week, steps.
    $stranger = new ReviewSeed;
    $stranger->weight = fn (int $offset): float => 120.0;
    $stranger->eatenShare = 0.5;
    $stranger->muscleFeedback = ReviewSeed::highFatigue(ReviewSeed::sessionKey(1));
    $stranger->planWeekRows = [['start_date' => '2026-10-05', 'is_rough' => true, 'reviewed_at' => '2026-10-12 08:00:00']];
    $stranger->steps = array_fill_keys(array_map(fn (int $d) => ReviewSeed::day($d), range(-7, -1)), 20000);
    $stranger->exerciseFeedback = [['session' => ReviewSeed::sessionKey(1), 'exercise' => 'Bench Press', 'pain' => 'aLot']];
    $other = $stranger->seed();

    $analysis = reviewed();

    expect($analysis->recommendation->reason->kind)->toBe(RecommendationReasonKind::OnTarget)
        ->and($analysis->weighInRows[0]->weightKg)->toBeLessThan(100)
        ->and($analysis->meals->total())->toBe(28)
        ->and($analysis->weeks)->toHaveCount(4)
        ->and($analysis->weeks[3]->averageIntakeKcal())->toEqualWithDelta(2400, 1e-6)
        ->and($analysis->training->sessionsDone)->toBe(3)
        ->and($analysis->training->setsDone)->toBe(20)
        ->and($analysis->training->jointPain)->toBe([])
        ->and($analysis->fatigue)->toBeNull()
        ->and($analysis->stepOffer)->toBeNull()
        ->and($analysis->weeks[3]->isRough)->toBeFalse()
        ->and(PlanWeek::query()->where('user_id', $other->id)->count())->toBe(1);
});
