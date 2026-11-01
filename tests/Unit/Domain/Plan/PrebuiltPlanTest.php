<?php

use App\Domain\Model\Phase;
use App\Domain\Model\PhaseType;
use App\Domain\Plan\PhaseRules;
use App\Domain\Plan\PlanLayout;
use App\Domain\Plan\PlannedMesocycle;
use App\Domain\Plan\PlanRules;
use App\Domain\Plan\PrebuiltPlan;
use App\Domain\Plan\WeekRange;
use Tests\Unit\Domain\Plan\PlanFixtures as F;

function prebuiltLayout(PrebuiltPlan $plan, int $start = 0): PlanLayout
{
    $next = 0;

    return $plan->layout($start, F::grid(), function () use (&$next) {
        return 'phase-'.$next++;
    });
}

/** @return list<array{int, int}> */
function prebuiltSpans(PlanLayout $layout): array
{
    return array_map(fn (PlannedMesocycle $mesocycle) => [$mesocycle->startWeek, $mesocycle->weeks], $layout->mesocycles);
}

function prebuiltRange(Phase $phase): WeekRange
{
    $weeks = PlanRules::weeks($phase, F::grid());
    expect($weeks)->not->toBeNull();

    return $weeks ?? new WeekRange(0, 0);
}

/** @return list<WeekRange> */
function prebuiltRanges(PlanLayout $layout): array
{
    return array_map(prebuiltRange(...), $layout->phases);
}

it('PLAN-030 there are two prebuilt plans of 24 weeks', function () {
    expect(PrebuiltPlan::cases())->toBe([PrebuiltPlan::MuscleGain, PrebuiltPlan::FatLoss])
        ->and(PrebuiltPlan::LENGTH_IN_WEEKS)->toBe(24);
});

it('PLAN-030 the muscle-gain plan layout', function () {
    $layout = prebuiltLayout(PrebuiltPlan::MuscleGain);

    expect(prebuiltSpans($layout))->toBe([[0, 5], [5, 4], [9, 4], [13, 4], [18, 6]])
        ->and($layout->activeRestWeeks)->toBe([16, 17])
        ->and(array_map(fn (Phase $phase) => $phase->type, $layout->phases))
        ->toBe([PhaseType::MuscleGain, PhaseType::MuscleGain, PhaseType::Maintenance, PhaseType::FatLoss])
        ->and(array_map(fn (Phase $phase) => $phase->targetRatePercentPerWeek, $layout->phases))->toBe([0.5, 0.375, 0.0, -0.75])
        ->and(prebuiltRanges($layout))
        ->toEqual([new WeekRange(0, 9), new WeekRange(9, 16), new WeekRange(16, 18), new WeekRange(18, 24)])
        ->and(array_map(fn (Phase $phase) => $phase->id, $layout->phases))->toBe(['phase-0', 'phase-1', 'phase-2', 'phase-3']);
});

it('PLAN-030 the fat-loss plan layout', function () {
    $layout = prebuiltLayout(PrebuiltPlan::FatLoss);

    expect(prebuiltSpans($layout))->toBe([[0, 5], [5, 5], [10, 5], [16, 4], [20, 4]])
        ->and($layout->activeRestWeeks)->toBe([14, 15])
        ->and(array_map(fn (Phase $phase) => $phase->type, $layout->phases))
        ->toBe([PhaseType::FatLoss, PhaseType::Maintenance, PhaseType::FatLoss])
        ->and(array_map(fn (Phase $phase) => $phase->targetRatePercentPerWeek, $layout->phases))->toBe([-0.75, 0.0, -0.75])
        ->and(prebuiltRanges($layout))->toEqual([new WeekRange(0, 14), new WeekRange(14, 16), new WeekRange(16, 24)]);
});

it('PLAN-030 active rest is the deload of the mesocycle before it plus a week off', function () {
    foreach (PrebuiltPlan::cases() as $plan) {
        $layout = prebuiltLayout($plan);
        $before = array_values(array_filter(
            $layout->mesocycles, fn (PlannedMesocycle $mesocycle) => $mesocycle->deloadWeek() === $layout->activeRestWeeks[0],
        ));

        expect($before)->not->toBe([], $plan->name)
            ->and($layout->activeRestWeeks[1])->toBe($before[0]->nextStartWeek(), $plan->name);
    }
});

it('PLAN-030 each plan fills exactly 24 weeks with contiguous phases', function () {
    $grid = F::grid();
    foreach (PrebuiltPlan::cases() as $plan) {
        foreach ([0, 3] as $start) {
            $layout = prebuiltLayout($plan, $start);
            $label = "{$plan->name} {$start}";
            $phases = $layout->phases;

            expect($phases[0]->start)->toEqual($grid->start($start), $label)
                ->and($phases[count($phases) - 1]->end)->toEqual($grid->start($start + 24), $label);
            foreach (array_slice($phases, 1) as $index => $next) {
                expect($phases[$index]->end)->toEqual($next->start, $label);
            }
            expect($layout->mesocycles[0]->startWeek)->toBe($start)
                ->and($layout->mesocycles[count($layout->mesocycles) - 1]->nextStartWeek())->toBe($start + 24);
        }
    }
});

it('PLAN-030 a later start shifts every week', function () {
    $layout = prebuiltLayout(PrebuiltPlan::FatLoss, 3);

    expect(prebuiltSpans($layout))->toBe([[3, 5], [8, 5], [13, 5], [19, 4], [23, 4]])
        ->and($layout->activeRestWeeks)->toBe([17, 18])
        ->and(prebuiltRanges($layout))->toEqual([new WeekRange(3, 17), new WeekRange(17, 19), new WeekRange(19, 27)]);
});

it('PLAN-030 every phase passes the phase rules', function () {
    foreach (PrebuiltPlan::cases() as $plan) {
        $layout = prebuiltLayout($plan);
        foreach ($layout->phases as $phase) {
            $weeks = prebuiltRange($phase);
            $dietBreaks = $phase->type->isFatLoss()
                ? count(array_filter($layout->mesocycles, fn (PlannedMesocycle $mesocycle) => $weeks->contains($mesocycle->deloadWeek())))
                : 0;

            expect(PhaseRules::validate($phase->type, $weeks->count(), $phase->targetRatePercentPerWeek, $dietBreaks))
                ->toBeNull("{$plan->name} {$phase->type->value} {$weeks->lower}..<{$weeks->upper}");
        }
    }
});

it('SAFE-006 the prebuilt plans never hold more than sixteen deficit weeks in one phase', function () {
    foreach (PrebuiltPlan::cases() as $plan) {
        foreach (prebuiltLayout($plan)->phases as $phase) {
            if ($phase->type === PhaseType::FatLoss) {
                expect(prebuiltRange($phase)->count())->toBeLessThanOrEqual(16);
            }
        }
    }
});

it('SAFE-007 the prebuilt plans never hold more than sixteen weeks of gaining', function () {
    foreach (PrebuiltPlan::cases() as $plan) {
        foreach (prebuiltLayout($plan)->phases as $phase) {
            if ($phase->type === PhaseType::MuscleGain) {
                expect(prebuiltRange($phase)->count())->toBeLessThanOrEqual(16);
            }
        }
    }
});
