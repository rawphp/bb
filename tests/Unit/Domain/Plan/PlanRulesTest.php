<?php

use App\Domain\Model\ClosedRange;
use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\Phase;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Plan;
use App\Domain\Plan\DatedMesocycle;
use App\Domain\Plan\DeloadCalories;
use App\Domain\Plan\PhaseRefusal;
use App\Domain\Plan\PlannedMesocycle;
use App\Domain\Plan\PlanRules;
use App\Domain\Plan\PlanWeekGrid;
use App\Domain\Plan\WeekRange;
use Tests\Unit\Domain\Plan\PlanFixtures as F;

function appendPhase(PhaseType $type, ?int $weeks, ?float $rate, Plan $plan, int $dietBreakWeeks = 0): Plan
{
    return PlanRules::appending('new', $type, $weeks, $rate, $plan, F::grid(), $dietBreakWeeks);
}

it('PLAN-006 appending to an empty plan starts at week zero', function () {
    $grid = F::grid();
    $result = appendPhase(PhaseType::MuscleGain, 12, 0.375, F::plan([]));

    expect($result->phases)->toHaveCount(1)
        ->and($result->phases[0]->id)->toBe('new')
        ->and($result->phases[0]->type)->toBe(PhaseType::MuscleGain)
        ->and($result->phases[0]->start)->toEqual($grid->start(0))
        ->and($result->phases[0]->end)->toEqual($grid->start(12))
        ->and($result->phases[0]->targetRatePercentPerWeek)->toBe(0.375);
});

it('PLAN-006 appending starts where the last phase ends', function () {
    $grid = F::grid();
    $plan = F::plan([F::phase(PhaseType::MuscleGain, 0, 12, 0.375)]);

    $result = appendPhase(PhaseType::FatLoss, 8, -0.75, $plan);

    expect($result->phases)->toHaveCount(2)
        ->and($result->phases[0])->toBe($plan->phases[0])
        ->and($result->phases[1]->start)->toEqual($grid->start(12))
        ->and($result->phases[1]->end)->toEqual($grid->start(20))
        ->and($result->phases[1]->targetRatePercentPerWeek)->toBe(-0.75);
});

it('PLAN-006 appending without a rate stores zero', function () {
    $result = appendPhase(PhaseType::MuscleGain, 5, null, F::plan([]));

    expect($result->phases[0]->targetRatePercentPerWeek)->toBe(0.0);
});

it('PLAN-006 cannot append after an open-ended phase', function () {
    $plan = F::plan([F::phase(PhaseType::Maintenance, 0, null)]);

    expect(F::refusal(fn () => appendPhase(PhaseType::FatLoss, 8, -0.75, $plan)))
        ->toEqual(PhaseRefusal::previousPhaseHasNoEnd());
});

it('PLAN-006 cannot append after a phase that ends mid-week', function () {
    $last = new Phase(id: 'last', type: PhaseType::Maintenance, start: F::grid()->start(0), end: F::date(2026, 1, 28), targetRatePercentPerWeek: 0);

    expect(F::refusal(fn () => appendPhase(PhaseType::FatLoss, 8, -0.75, F::plan([$last]))))
        ->toEqual(PhaseRefusal::notOnWeekBoundary());
});

it('PLAN-006 appending applies the phase limits', function () {
    $plan = F::plan([]);

    expect(F::refusal(fn () => appendPhase(PhaseType::MuscleGain, 17, 0.25, $plan)))->toEqual(PhaseRefusal::tooLong(16))
        ->and(F::refusal(fn () => appendPhase(PhaseType::FatLoss, 8, 0.25, $plan)))
        ->toEqual(PhaseRefusal::rateOutOfRange(new ClosedRange(-1.0, -0.25)))
        ->and(F::refusal(fn () => appendPhase(PhaseType::FatLoss, null, -0.75, $plan)))
        ->toEqual(PhaseRefusal::endDateRequired());
});

it('PLAN-006 appending an open-ended Maintenance phase', function () {
    $plan = F::plan([F::phase(PhaseType::FatLoss, 0, 8, -0.75)]);

    $result = appendPhase(PhaseType::Maintenance, null, 0, $plan);

    expect($result->phases[1]->start)->toEqual(F::grid()->start(8))
        ->and($result->phases[1]->end)->toBeNull();
});

it('PLAN-006 only the current and next phase are detailed', function () {
    expect(PlanRules::isDetailed(0, currentPhaseIndex: 0))->toBeTrue()
        ->and(PlanRules::isDetailed(1, currentPhaseIndex: 0))->toBeTrue()
        ->and(PlanRules::isDetailed(2, currentPhaseIndex: 0))->toBeFalse()
        ->and(PlanRules::isDetailed(2, currentPhaseIndex: 1))->toBeTrue()
        ->and(PlanRules::isDetailed(3, currentPhaseIndex: 1))->toBeFalse();
});

it('PLAN-002 weeks of a phase', function () {
    expect(PlanRules::weeks(F::phase(PhaseType::MuscleGain, 3, 9, 0.375), F::grid()))->toEqual(new WeekRange(3, 9));
});

it('PLAN-002 an open-ended phase runs without end', function () {
    expect(PlanRules::weeks(F::phase(PhaseType::Maintenance, 4, null), F::grid()))->toEqual(new WeekRange(4, PHP_INT_MAX));
});

it('PLAN-002 a phase ending mid-week takes in that week', function () {
    // Week 3 runs Monday 26 January to Sunday 1 February; an end on Wednesday 28 January still covers it.
    $phase = new Phase(id: 'phase', type: PhaseType::Maintenance, start: F::grid()->start(2), end: F::date(2026, 1, 28), targetRatePercentPerWeek: 0);

    expect(PlanRules::weeks($phase, F::grid()))->toEqual(new WeekRange(2, 4));
});

it('PLAN-002 a phase ending before it starts has no weeks', function () {
    $phase = new Phase(id: 'phase', type: PhaseType::Maintenance, start: F::grid()->start(2), end: F::date(2026, 1, 7), targetRatePercentPerWeek: 0);

    expect(PlanRules::weeks($phase, F::grid()))->toEqual(new WeekRange(2, 2));
});

it('PLAN-002 a phase not starting on a boundary has no weeks', function () {
    $phase = new Phase(id: 'phase', type: PhaseType::Maintenance, start: F::date(2026, 1, 7), end: null, targetRatePercentPerWeek: 0);

    expect(PlanRules::weeks($phase, F::grid()))->toBeNull();
});

it('PLAN-007 a training-only phase is one default mesocycle with no rate', function () {
    $grid = F::grid();

    $intermediate = PlanRules::trainingOnlyPhase('a', PhaseType::Maintenance, 3, ExperienceLevel::Intermediate, $grid);
    expect($intermediate->type)->toBe(PhaseType::Maintenance)
        ->and($intermediate->targetRatePercentPerWeek)->toBe(0.0)
        ->and($intermediate->start)->toEqual($grid->start(3))
        ->and($intermediate->end)->toEqual($grid->start(8));

    $beginner = PlanRules::trainingOnlyPhase('b', PhaseType::MuscleGain, 0, ExperienceLevel::Beginner, $grid);
    expect($beginner->end)->toEqual($grid->start(6))
        ->and($beginner->targetRatePercentPerWeek)->toBe(0.0);
});

it('PLAN-007 a training-only phase repeats where it ends', function () {
    $grid = F::grid();
    $first = PlanRules::trainingOnlyPhase('first', PhaseType::Maintenance, 3, ExperienceLevel::Intermediate, $grid);

    $second = PlanRules::repeatingTrainingOnlyPhase($first, $grid, 'second');
    expect($second)->not->toBeNull()
        ->and($second?->type)->toBe(PhaseType::Maintenance)
        ->and($second?->targetRatePercentPerWeek)->toBe(0.0)
        ->and($second?->start)->toEqual($grid->start(8))
        ->and($second?->end)->toEqual($grid->start(13))
        ->and($second?->id)->not->toBe($first->id);

    $third = PlanRules::repeatingTrainingOnlyPhase($second, $grid, 'third');
    expect($third?->start)->toEqual($grid->start(13))
        ->and($third?->end)->toEqual($grid->start(18));
});

it('PLAN-007 an open-ended phase does not repeat', function () {
    expect(PlanRules::repeatingTrainingOnlyPhase(F::phase(PhaseType::Maintenance, 0, null), F::grid(), 'next'))->toBeNull();
});

// PLAN-002: a mesocycle crossing from Muscle gain into Fat loss.

function crossingPlan(): Plan
{
    return F::plan([
        F::phase(PhaseType::MuscleGain, 0, 8, 0.375),
        F::phase(PhaseType::FatLoss, 8, 14, -0.75),
    ]);
}

/** Weeks 6 to 10, deload in week 10. */
function crossingMesocycle(): PlannedMesocycle
{
    return new PlannedMesocycle(startWeek: 6, weeks: 5);
}

it('PLAN-002 phase covering a week', function () {
    $plan = crossingPlan();
    $grid = F::grid();

    expect(PlanRules::phaseIndexCoveringWeek(0, $plan, $grid))->toBe(0)
        ->and(PlanRules::phaseIndexCoveringWeek(7, $plan, $grid))->toBe(0)
        ->and(PlanRules::phaseIndexCoveringWeek(8, $plan, $grid))->toBe(1)
        ->and(PlanRules::phaseIndexCoveringWeek(13, $plan, $grid))->toBe(1)
        ->and(PlanRules::phaseIndexCoveringWeek(14, $plan, $grid))->toBeNull();
});

it('PLAN-002 an open-ended phase covers every later week', function () {
    $plan = F::plan([
        F::phase(PhaseType::FatLoss, 0, 8, -0.75),
        F::phase(PhaseType::Maintenance, 8, null),
    ]);

    expect(PlanRules::phaseIndexCoveringWeek(500, $plan, F::grid()))->toBe(1);
});

it('PLAN-002 a mesocycle belongs to the phase covering its first week', function () {
    expect(PlanRules::owningPhaseIndex(crossingMesocycle(), crossingPlan(), F::grid()))->toBe(0)
        ->and(PlanRules::owningPhaseIndex(new PlannedMesocycle(startWeek: 8, weeks: 5), crossingPlan(), F::grid()))->toBe(1);
});

it('PLAN-002 each week of a mesocycle follows the phase covering that week', function () {
    $governing = array_map(
        fn (int $week) => PlanRules::governingPhaseIndex($week, crossingMesocycle(), crossingPlan(), F::grid()),
        range(6, 10),
    );

    expect($governing)->toBe([0, 0, 1, 1, 1]);
});

it('PLAN-002 the deload of a crossing mesocycle is a diet break in the fat-loss phase', function () {
    $plan = crossingPlan();
    $index = PlanRules::governingPhaseIndex(crossingMesocycle()->deloadWeek(), crossingMesocycle(), $plan, F::grid());

    expect($index)->not->toBeNull()
        ->and($plan->phases[$index]->type)->toBe(PhaseType::FatLoss)
        ->and(DeloadCalories::isDietBreak($plan->phases[$index]->type, isDeload: true, dietBreakTurnedOff: false))->toBeTrue();
});

it("PLAN-002 weeks past the plan's end follow the owning phase", function () {
    $late = new PlannedMesocycle(startWeek: 12, weeks: 5);

    expect(PlanRules::owningPhaseIndex($late, crossingPlan(), F::grid()))->toBe(1)
        ->and(PlanRules::governingPhaseIndex(13, $late, crossingPlan(), F::grid()))->toBe(1)
        ->and(PlanRules::governingPhaseIndex(16, $late, crossingPlan(), F::grid()))->toBe(1);
});

it('PLAN-002 a mesocycle with no phase has no owner', function () {
    $beyond = new PlannedMesocycle(startWeek: 20, weeks: 5);

    expect(PlanRules::owningPhaseIndex($beyond, crossingPlan(), F::grid()))->toBeNull()
        ->and(PlanRules::governingPhaseIndex(22, $beyond, crossingPlan(), F::grid()))->toBeNull();
});

it('PLAN-025 appending counts diet breaks towards the fat-loss cap', function () {
    $result = appendPhase(PhaseType::FatLoss, 18, -0.75, F::plan([]), dietBreakWeeks: 2);

    expect($result->phases[0]->end)->toEqual(F::grid()->start(18));
});

it('SAFE-006 appending eighteen fat-loss weeks without diet breaks is refused', function () {
    expect(F::refusal(fn () => appendPhase(PhaseType::FatLoss, 18, -0.75, F::plan([]), dietBreakWeeks: 0)))
        ->toEqual(PhaseRefusal::tooLong(16));
});

/** @return array{Plan, Plan} */
function changedPlan(): array
{
    $plan = F::plan([
        F::phase(PhaseType::MuscleGain, 0, 2, 0.375, id: 'gain'),
        F::phase(PhaseType::FatLoss, 2, 6, -0.75, id: 'cut'),
        F::phase(PhaseType::Maintenance, 6, null, id: 'hold'),
    ]);

    return [$plan, PlanRules::changingReviewDay($plan, F::FRIDAY, F::date(2026, 1, 21))];
}

it('PLAN-008 changing the review day is remembered by the plan', function () {
    [, $changed] = changedPlan();
    $expected = F::grid()->changingReviewDay(F::FRIDAY, F::date(2026, 1, 21));
    $rebuilt = PlanWeekGrid::forPlan($changed);

    foreach (range(0, 19) as $week) {
        expect($rebuilt->start($week))->toEqual($expected->start($week), "week {$week}");
    }
    expect($rebuilt->reviewWeekday())->toBe($expected->reviewWeekday())
        ->and($changed->currentReviewWeekday())->toBe(F::FRIDAY)
        ->and($changed->reviewWeekday)->toBe(F::MONDAY)
        ->and($changed->reviewDayChanges)->toHaveCount(1);
});

it('PLAN-008 changing the review day keeps phases on boundaries and week indices', function () {
    [$plan, $changed] = changedPlan();
    $before = F::grid();
    $after = PlanWeekGrid::forPlan($changed);

    foreach ($plan->phases as $index => $old) {
        $new = $changed->phases[$index];
        expect(PlanRules::weeks($new, $after))->toEqual(PlanRules::weeks($old, $before))
            ->and($after->isBoundary($new->start))->toBeTrue();
        if ($new->end !== null) {
            expect($after->isBoundary($new->end))->toBeTrue();
        }
    }
    expect($changed->phases[0])->toEqual($plan->phases[0])
        ->and($changed->phases[1]->start)->toEqual($plan->phases[1]->start)
        ->and($changed->phases[1]->end)->not->toEqual($plan->phases[1]->end)
        ->and($changed->phases[2]->end)->toBeNull();
});

it('PLAN-008 changing the review day before the plan starts rebases without a change', function () {
    $plan = F::plan([F::phase(PhaseType::MuscleGain, 0, 4, 0.375)]);

    $changed = PlanRules::changingReviewDay($plan, F::FRIDAY, F::date(2025, 12, 20));

    expect($changed->reviewWeekday)->toBe(F::FRIDAY)
        ->and($changed->reviewDayChanges)->toBe([])
        ->and($changed->currentReviewWeekday())->toBe(F::FRIDAY);
});

// PLAN-002 covering mesocycle.

it('PLAN-002 the mesocycle whose weeks include the week covers it', function () {
    $covering = PlanRules::coveringMesocycle(5, [F::span(0, 4), F::span(4, 5)], F::grid());

    expect($covering?->index)->toBe(1)
        ->and($covering?->placed)->toEqual(new PlannedMesocycle(startWeek: 4, weeks: 5));
});

it('PLAN-002 no mesocycle covers a week outside all of them', function () {
    expect(PlanRules::coveringMesocycle(4, [F::span(0, 4)], F::grid()))->toBeNull();
});

it('PLAN-002 when mesocycles overlap the one that started last covers', function () {
    $mesocycles = [F::span(0, 6), F::span(3, 4), F::span(1, 6)];

    expect(PlanRules::coveringMesocycle(4, $mesocycles, F::grid())?->index)->toBe(1)
        ->and(PlanRules::coveringMesocycle(2, $mesocycles, F::grid())?->index)->toBe(2);
});

it('PLAN-002 of two starting the same week the first listed covers', function () {
    expect(PlanRules::coveringMesocycle(3, [F::span(2, 4), F::span(2, 4)], F::grid())?->index)->toBe(0);
});

it('PLAN-002 a mesocycle that began before the plan keeps its own weeks', function () {
    // Two weeks before the plan: its weeks 3 and 4 are the plan's weeks 0 and 1, and it ends there.
    $early = new DatedMesocycle(F::date(2025, 12, 22), 4);

    expect(PlanRules::coveringMesocycle(1, [$early], F::grid())?->placed)->toEqual(new PlannedMesocycle(startWeek: -2, weeks: 4))
        ->and(PlanRules::coveringMesocycle(2, [$early], F::grid()))->toBeNull();
});

it('PLAN-002 an unfinished mesocycle covers nothing', function () {
    expect(PlanRules::coveringMesocycle(1, [null, F::span(0, 4)], F::grid())?->index)->toBe(1)
        ->and(PlanRules::coveringMesocycle(1, [null], F::grid()))->toBeNull();
});
