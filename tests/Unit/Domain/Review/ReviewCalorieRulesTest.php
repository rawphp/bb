<?php

// The same tests as rp-clone's ReviewCalorieRulesTests.swift (pin 2aa1edeb), one for one.

use App\Domain\Model\PhaseType;
use App\Domain\Model\PlanWeek;
use App\Domain\Model\StepPlanDay;
use App\Domain\Review\CalorieOptionKind;
use App\Domain\Review\CalorieOptions;
use App\Domain\Review\DeloadMove;
use App\Domain\Review\DeloadMove\Plan;
use App\Domain\Review\RepeatThisWeek;
use App\Domain\Review\ReviewWeek;
use App\Domain\Review\StepPlanOffer;
use App\Domain\Review\StepPlanUpdate;
use Tests\Unit\Domain\Plan\PlanFixtures;

function calorieWeek(bool $deload = false, bool $dietBreak = false, bool $away = false): PlanWeek
{
    return new PlanWeek(PlanFixtures::date(2026, 1, 5), isDeload: $deload, isDietBreak: $dietBreak, isAway: $away);
}

function repeatReviewWeek(int $index, float $target, bool $dietBreak = false, bool $away = false, bool $deload = false): ReviewWeek
{
    return new ReviewWeek(
        week: new PlanWeek(PlanFixtures::date(2026, 1, 5 + 7 * $index), isDeload: $deload, isDietBreak: $dietBreak, isAway: $away),
        loggedIntakeKcal: [],
        targetKcal: $target,
    );
}

/** @return list<StepPlanDay> */
function calorieStepPlan(): array
{
    return [new StepPlanDay(1, 6000, 8000), new StepPlanDay(2, 7000, 9000)];
}

// REV-010

it('REV-010 fat loss offers the recommendation repeat choose own and drop fatigue', function () {
    $options = CalorieOptions::options(PhaseType::FatLoss, calorieWeek(), calorieWeek());
    expect($options->kinds)->toBe([
        CalorieOptionKind::Recommendation, CalorieOptionKind::RepeatThisWeek, CalorieOptionKind::ChooseOwn, CalorieOptionKind::DropFatigue,
    ])->and($options->nextIsDietBreak)->toBeFalse();
});

it('REV-010 drop fatigue is for fat loss only', function () {
    foreach ([PhaseType::MuscleGain, PhaseType::Maintenance] as $phase) {
        $options = CalorieOptions::options($phase, calorieWeek(), calorieWeek());
        expect($options->kinds)->toBe([CalorieOptionKind::Recommendation, CalorieOptionKind::RepeatThisWeek, CalorieOptionKind::ChooseOwn]);
    }
    // PLAN-009: Mini-cut counts as Fat loss.
    expect(CalorieOptions::options(PhaseType::MiniCut, calorieWeek(), calorieWeek())->kinds)->toContain(CalorieOptionKind::DropFatigue);
});

it('REV-010 drop fatigue is hidden when the week that ended was a diet break', function () {
    $marked = CalorieOptions::options(PhaseType::FatLoss, calorieWeek(dietBreak: true), calorieWeek());
    expect($marked->kinds)->not->toContain(CalorieOptionKind::DropFatigue)
        ->and($marked->kinds)->toContain(CalorieOptionKind::Recommendation);
    // PLAN-022: a Fat loss deload runs at maintenance, so it is a diet break.
    $deload = CalorieOptions::options(PhaseType::FatLoss, calorieWeek(deload: true), calorieWeek());
    expect($deload->kinds)->not->toContain(CalorieOptionKind::DropFatigue);
    // An ordinary week before it keeps Drop fatigue.
    $ordinary = CalorieOptions::options(PhaseType::FatLoss, calorieWeek(), calorieWeek());
    expect($ordinary->kinds)->toContain(CalorieOptionKind::DropFatigue);
});

// REV-012

it('REV-012 a diet break already on the plan leaves only repeat and choose own', function () {
    $options = CalorieOptions::options(PhaseType::FatLoss, calorieWeek(), calorieWeek(dietBreak: true));
    expect($options->nextIsDietBreak)->toBeTrue()
        ->and($options->kinds)->toBe([CalorieOptionKind::RepeatThisWeek, CalorieOptionKind::ChooseOwn]);
});

it('REV-012 a fat loss deload next week is a diet break at maintenance', function () {
    $options = CalorieOptions::options(PhaseType::FatLoss, calorieWeek(), calorieWeek(deload: true));
    expect($options->nextIsDietBreak)->toBeTrue()
        ->and($options->kinds)->toBe([CalorieOptionKind::RepeatThisWeek, CalorieOptionKind::ChooseOwn])
        ->and(CalorieOptions::DIET_BREAK_MESSAGE)->toBe('Next week is a diet break at maintenance calories');
});

it('REV-012 a deload outside fat loss keeps the usual options', function () {
    $options = CalorieOptions::options(PhaseType::MuscleGain, calorieWeek(), calorieWeek(deload: true));
    expect($options->nextIsDietBreak)->toBeFalse()
        ->and($options->kinds)->toBe([CalorieOptionKind::Recommendation, CalorieOptionKind::RepeatThisWeek, CalorieOptionKind::ChooseOwn]);
});

// REV-011

it('REV-011 a deload one or two weeks away is moved without asking and the diet break and deload are one week', function () {
    // A 5-week mesocycle from week 0 has its deload in week 4. Reviewing week 2 (3 weeks done) leaves it 2 away.
    $plan = DeloadMove::plan(mesocycleWeeks: 5, mesocycleStartWeek: 0, reviewedWeek: 2, nextWeek: 3, nextIsDeload: false);
    expect($plan)->toEqual(Plan::deloadNow(new DeloadMove(4)));
    // Reviewing week 3, the deload is next week already.
    expect(DeloadMove::plan(mesocycleWeeks: 5, mesocycleStartWeek: 0, reviewedWeek: 3, nextWeek: 4, nextIsDeload: true))
        ->toEqual(Plan::dietBreakOnly());
});

it('REV-011 otherwise it asks and yes is the default once three training weeks are done', function () {
    // An 8-week mesocycle reviewed after 2 weeks done: ask, default No.
    expect(DeloadMove::plan(mesocycleWeeks: 8, mesocycleStartWeek: 0, reviewedWeek: 1, nextWeek: 2, nextIsDeload: false))
        ->toEqual(Plan::askDeloadToo(false, new DeloadMove(3)));
    // After 3 weeks done: ask, default Yes.
    expect(DeloadMove::plan(mesocycleWeeks: 8, mesocycleStartWeek: 0, reviewedWeek: 2, nextWeek: 3, nextIsDeload: false))
        ->toEqual(Plan::askDeloadToo(true, new DeloadMove(4)));
});

it('REV-011 the mesocycle counts from its own start week', function () {
    // The mesocycle began in plan week 6: reviewing week 8 is 3 training weeks done.
    expect(DeloadMove::plan(mesocycleWeeks: 8, mesocycleStartWeek: 6, reviewedWeek: 8, nextWeek: 9, nextIsDeload: false))
        ->toEqual(Plan::askDeloadToo(true, new DeloadMove(4)));
});

it('REV-011 the RIR for the new length comes from TBL-023', function () {
    // 4 weeks is 3 training weeks and a deload: TBL-023 gives 3, 1, 0.
    expect((new DeloadMove(4))->rirByWeek())->toBe([3, 1, 0])
        ->and((new DeloadMove(6))->rirByWeek())->toBe([3, 2, 2, 1, 0]);
});

it('REV-011 without a mesocycle it is a diet break only', function () {
    expect(DeloadMove::plan(mesocycleWeeks: null, mesocycleStartWeek: null, reviewedWeek: 2, nextWeek: 3, nextIsDeload: false))
        ->toEqual(Plan::dietBreakOnly());
});

it('REV-011 a mesocycle too short to lose a week is a diet break only', function () {
    // Moving the deload to week 2 would leave one training week, and TBL-023 has no row for that.
    expect(DeloadMove::plan(mesocycleWeeks: 3, mesocycleStartWeek: 0, reviewedWeek: 0, nextWeek: 1, nextIsDeload: false))
        ->toEqual(Plan::dietBreakOnly());
});

// Repeat this week

it('REV-010 repeat keeps the week that ended average target', function () {
    $weeks = [repeatReviewWeek(0, 2400), repeatReviewWeek(1, 2300)];
    expect(RepeatThisWeek::targetKcal($weeks))->toBe(2300.0);
});

it('REV-010 repeat after a break or an away week goes back to the last ordinary week', function () {
    // REV-023: after a diet break, straight back to the last non-break target.
    expect(RepeatThisWeek::targetKcal([repeatReviewWeek(0, 2300), repeatReviewWeek(1, 2900, dietBreak: true)]))->toBe(2300.0)
        ->and(RepeatThisWeek::targetKcal([repeatReviewWeek(0, 2300), repeatReviewWeek(1, 2100, away: true)]))->toBe(2300.0)
        ->and(RepeatThisWeek::targetKcal([repeatReviewWeek(0, 2300), repeatReviewWeek(1, 2900, deload: true)]))->toBe(2300.0);
});

it('REV-010 repeat with no ordinary week keeps the week that ended', function () {
    expect(RepeatThisWeek::targetKcal([repeatReviewWeek(0, 2900, dietBreak: true)]))->toBe(2900.0)
        ->and(RepeatThisWeek::targetKcal([]))->toBeNull();
});

// steps

it('REV-027 adding steps raises every days range', function () {
    expect(StepPlanUpdate::adding(1500, calorieStepPlan()))
        ->toEqual([new StepPlanDay(1, 7500, 9500), new StepPlanDay(2, 8500, 10500)]);
});

it('REV-050 updating the step plan shifts every range by the difference to the nearest 500', function () {
    // Planned 8,000 a day on average, walked 11,200: 3,200 more, rounded to 3,000.
    $offer = new StepPlanOffer(actualAverage: 11_200, plannedAverage: 8_000);
    expect(StepPlanUpdate::matching($offer, calorieStepPlan()))
        ->toEqual([new StepPlanDay(1, 9000, 11000), new StepPlanDay(2, 10000, 12000)]);
});

it('REV-050 a lower average never takes the range below zero', function () {
    $offer = new StepPlanOffer(actualAverage: 2_000, plannedAverage: 8_000);
    $updated = StepPlanUpdate::matching($offer, calorieStepPlan());
    expect(array_map(fn (StepPlanDay $d) => $d->min, $updated))->toBe([0, 1000])
        ->and(array_map(fn (StepPlanDay $d) => $d->max, $updated))->toBe([2000, 3000]);
});

it('REV-027 the planned daily steps are the average of the range middles', function () {
    expect(StepPlanUpdate::plannedDailySteps(calorieStepPlan()))->toBe(7_500)
        ->and(StepPlanUpdate::plannedDailySteps([]))->toBe(0);
});
