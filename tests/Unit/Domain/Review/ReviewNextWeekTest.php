<?php

// The same tests as rp-clone's ReviewNextWeekTests.swift (pin 2aa1edeb), one for one.

use App\Domain\Macros\MoveRefusalReason;
use App\Domain\Macros\TargetRefusalReason;
use App\Domain\Model\DayType;
use App\Domain\Model\PhaseType;
use App\Domain\Retarget\OtherActivity;
use App\Domain\Review\BuiltWeekSummary;
use App\Domain\Review\NextWeekCalories;
use App\Domain\Review\ReviewNextWeek;
use App\Domain\Review\ReviewWorld;
use App\Domain\WeekBuilder\AwayWeek;
use App\Domain\WeekBuilder\AwayWeek\Calories;
use App\Domain\WeekBuilder\AwayWeek\Training;
use App\Domain\WeekBuilder\BuiltWeek;
use App\Domain\WeekBuilder\CalorieMove;
use App\Domain\WeekBuilder\WeekBuildRefusalReason;
use App\Domain\WeekBuilder\WeekDay;
use Tests\Unit\Domain\Plan\PlanFixtures;
use Tests\Unit\Domain\Review\NextWeekFixtures as F;
use Tests\Unit\Domain\WeekBuilder\WeekBuilderFixtures as W;

function plannedNextWeek(ReviewWorld $world): BuiltWeek
{
    return ReviewNextWeek::build($world, NextWeekCalories::planned());
}

// SET-004

it('SET-004 next weeks targets keep a stored other activitys energy', function () {
    $without = plannedNextWeek(F::world());
    $restIndex = array_key_first(array_filter($without->days, fn (WeekDay $d) => $d->day->type === DayType::Rest));
    $restDay = $without->days[$restIndex]->day->date->setTime(0, 0)->format('Y-m-d');

    $with = plannedNextWeek(F::world(otherActivities: [$restDay => new OtherActivity('Run', 60, DayType::Moderate)]));

    expect($with->days[$restIndex]->day->type)->toBe(DayType::Moderate)
        ->and($with->days[$restIndex]->target->kcal)->toBeGreaterThan($without->days[$restIndex]->target->kcal);
});

// REV-002

it('REV-002 repeat keeps the average of the week that ended', function () {
    $world = F::world();
    $target = BuiltWeekSummary::averageKcal(plannedNextWeek($world)) + 140;
    $repeated = ReviewNextWeek::repeatWeek($world, $target);
    expect(BuiltWeekSummary::averageKcal($repeated))->toEqualWithDelta($target, F::ACC)
        ->and($repeated->days)->toHaveCount(7);
});

it('REV-002 a diet break already on the plan wins over the repeat', function () {
    $world = F::world(dietBreak: true);
    $planned = plannedNextWeek($world);
    $repeated = ReviewNextWeek::repeatWeek($world, 1_900);
    expect(F::kcal($repeated))->toEqual(F::kcal($planned)) // the planned week's own rules apply
        ->and($planned->week->isDietBreak)->toBeTrue()
        ->and(abs(BuiltWeekSummary::averageKcal($repeated) - 1_900))->toBeGreaterThan(50);
});

it('REV-002 a deload at maintenance wins over the repeat', function () {
    // A 3-week mesocycle from week 0: week 2 is its deload, and a Fat loss deload runs at maintenance.
    $world = F::world(nextWeek: 2, mesocycleWeeks: 3);
    $planned = plannedNextWeek($world);
    expect($planned->week->isDeload)->toBeTrue()
        ->and($planned->week->isDietBreak)->toBeTrue();
    $repeated = ReviewNextWeek::repeatWeek($world, 1_900);
    expect(F::kcal($repeated))->toEqual(F::kcal($planned));
});

it('REV-002 an away week wins over the repeat', function () {
    $world = F::world(away: new AwayWeek(Training::Pause, Calories::Maintenance));
    $planned = plannedNextWeek($world);
    expect($planned->week->isAway)->toBeTrue()
        ->and(F::kcal(ReviewNextWeek::repeatWeek($world, 1_900)))->toEqual(F::kcal($planned));
});

it('REV-002 without a calorie to repeat the week follows the phase', function () {
    $world = F::world();
    expect(F::kcal(ReviewNextWeek::repeatWeek($world, null)))->toEqual(F::kcal(plannedNextWeek($world)));
});

it('REV-002 a late save gives past days the repeat and today onward the chosen option', function () {
    $world = F::world();
    $base = BuiltWeekSummary::averageKcal(plannedNextWeek($world));
    $repeatKcal = $base + 100;
    $chosen = NextWeekCalories::average($base - 150);
    // Week 2 starts Monday 19 January; today is Wednesday the 21st.
    $today = PlanFixtures::date(2026, 1, 21)->modify('+10 hours');
    $late = ReviewNextWeek::forSave($world, $chosen, $repeatKcal, [], today: $today);
    $repeated = ReviewNextWeek::repeatWeek($world, $repeatKcal);
    $option = ReviewNextWeek::build($world, $chosen);

    expect(array_slice(F::kcal($late), 0, 2))->toEqual(array_slice(F::kcal($repeated), 0, 2))
        ->and(array_slice(F::kcal($late), 2))->toEqual(array_slice(F::kcal($option), 2))
        ->and(array_map(fn (WeekDay $d) => $d->day->date, $late->days))->toEqual(array_map(fn (WeekDay $d) => $d->day->date, $option->days));
});

it('REV-002 saving on the review day gives every day the chosen option', function () {
    $world = F::world();
    $chosen = NextWeekCalories::average(BuiltWeekSummary::averageKcal(plannedNextWeek($world)) - 100);
    $early = ReviewNextWeek::forSave($world, $chosen, null, [], today: PlanFixtures::date(2026, 1, 19));
    expect(F::kcal($early))->toEqual(F::kcal(ReviewNextWeek::build($world, $chosen)));
});

// REV-004

it('REV-004 a move shifts calories between days and the weekly total holds', function () {
    $world = F::world();
    $before = plannedNextWeek($world);
    $after = ReviewNextWeek::build($world, NextWeekCalories::planned(), [new CalorieMove(0, 5, 120)]);
    expect(F::kcal($after)[0])->toEqualWithDelta(F::kcal($before)[0] - 120, F::ACC)
        ->and(F::kcal($after)[5])->toEqualWithDelta(F::kcal($before)[5] + 120, F::ACC)
        ->and(array_sum(F::kcal($after)))->toEqualWithDelta(array_sum(F::kcal($before)), F::ACC)
        ->and(array_sum(array_map(fn ($m) => $m->meal->target->kcal, $after->days[0]->meals)))
        ->toEqualWithDelta(F::kcal($after)[0], 1e-3);
});

it('REV-004 a move outside the band is refused', function () {
    // NUT-022: no day goes below 80% or above 125% of the average.
    $refusal = W::refusal(fn () => ReviewNextWeek::build(F::world(), NextWeekCalories::planned(), [new CalorieMove(0, 5, 900)]));
    expect($refusal->reason)->toBe(WeekBuildRefusalReason::Move)
        ->and($refusal->move->reason)->toBe(MoveRefusalReason::OutsideBand);
});

it('REV-004 days already past in a late save are locked out of moves', function () {
    $today = PlanFixtures::date(2026, 1, 21);
    $refusal = W::refusal(fn () => ReviewNextWeek::forSave(
        F::world(), NextWeekCalories::planned(), null, [new CalorieMove(1, 5, 100)], today: $today,
    ));
    expect($refusal->reason)->toBe(WeekBuildRefusalReason::Move)
        ->and($refusal->move->reason)->toBe(MoveRefusalReason::DayCompleted)
        ->and($refusal->move->dayIndex)->toBe(1);
});

// REV-013

it('REV-013 a week reports its average calories and macro split', function () {
    $week = plannedNextWeek(F::world());
    $days = array_map(fn (WeekDay $d) => $d->target, $week->days);
    expect(BuiltWeekSummary::averageKcal($week))->toEqualWithDelta(array_sum(array_map(fn ($t) => $t->kcal, $days)) / 7, F::ACC)
        ->and(BuiltWeekSummary::averageProteinG($week))->toEqualWithDelta(array_sum(array_map(fn ($t) => $t->proteinG, $days)) / 7, F::ACC)
        ->and(BuiltWeekSummary::averageFatG($week))->toEqualWithDelta(array_sum(array_map(fn ($t) => $t->fatG, $days)) / 7, F::ACC)
        ->and(BuiltWeekSummary::averageCarbsG($week))->toEqualWithDelta(array_sum(array_map(fn ($t) => $t->carbsG, $days)) / 7, F::ACC);
});

it('SAFE-003 a training only week has workouts and no calories', function () {
    $week = plannedNextWeek(F::world(trainingOnly: true));
    expect($week->days)->toHaveCount(7)
        ->and(BuiltWeekSummary::averageKcal($week))->toBeNull()
        ->and(array_filter($week->days, fn (WeekDay $d) => $d->target !== null))->toBe([])
        ->and(array_filter($week->days, fn (WeekDay $d) => $d->workouts !== []))->toHaveCount(3);
});

// REV-010, REV-011

it('REV-010 a chosen average that breaks the safety floors is refused', function () {
    $refusal = W::refusal(fn () => ReviewNextWeek::build(F::world(), NextWeekCalories::average(900)));
    expect($refusal->reason)->toBe(WeekBuildRefusalReason::Targets);
});

it('REV-011 drop fatigue makes next week a diet break at maintenance', function () {
    $world = F::world();
    $ordinary = plannedNextWeek($world);
    $dietBreak = ReviewNextWeek::build($world, NextWeekCalories::dietBreak());
    expect($dietBreak->week->isDietBreak)->toBeTrue()
        ->and(BuiltWeekSummary::averageKcal($dietBreak))->toBeGreaterThan(BuiltWeekSummary::averageKcal($ordinary) + 200);
});

it('REV-011 moving the deload to next week makes it a deload week too', function () {
    // The 5-week mesocycle is cut to 3 weeks, so week 2 is its last: the deload.
    $built = ReviewNextWeek::build(F::world(nextWeek: 2, mesocycleWeeks: 3), NextWeekCalories::dietBreak());
    expect($built->week->isDeload)->toBeTrue()
        ->and($built->week->isDietBreak)->toBeTrue();
    $plain = F::world(nextWeek: 2, mesocycleWeeks: 5);
    expect(ReviewNextWeek::build($plain, NextWeekCalories::dietBreak())->week->isDeload)->toBeFalse();
});

// SAFE-011

it('SAFE-011 at BMI 17 or less during fat loss the phases own cut is refused and maintenance or more is not', function () {
    $world = F::world(profile: F::thin());
    $refusal = W::refusal(fn () => plannedNextWeek($world));
    expect($refusal->targets->reason)->toBe(TargetRefusalReason::BmiAtOrBelowFloor);
    $maintenance = BuiltWeekSummary::averageKcal(ReviewNextWeek::build($world, NextWeekCalories::dietBreak()));
    // The chosen calories are checked on their own change from maintenance, "raise to maintenance" included.
    foreach ([$maintenance, $maintenance + 100] as $kcal) {
        $built = ReviewNextWeek::build($world, NextWeekCalories::average($kcal));
        expect(BuiltWeekSummary::averageKcal($built))->toEqualWithDelta($kcal, F::ACC);
    }
    $below = W::refusal(fn () => ReviewNextWeek::build($world, NextWeekCalories::average($maintenance - 100)));
    expect($below->targets->reason)->toBe(TargetRefusalReason::BmiAtOrBelowFloor);
});

it('SAFE-011 at BMI 17 or less in maintenance a cut is refused and a raise is not', function () {
    $world = F::world(phase: PhaseType::Maintenance, profile: F::thin());
    $maintenance = BuiltWeekSummary::averageKcal(plannedNextWeek($world));
    $raised = ReviewNextWeek::build($world, NextWeekCalories::average($maintenance + 100));
    expect(BuiltWeekSummary::averageKcal($raised))->toEqualWithDelta($maintenance + 100, F::ACC);
    $below = W::refusal(fn () => ReviewNextWeek::build($world, NextWeekCalories::average($maintenance - 100)));
    expect($below->targets->reason)->toBe(TargetRefusalReason::BmiAtOrBelowFloor);
});
