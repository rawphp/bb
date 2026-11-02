<?php

use App\Domain\Progress\EstimatedOneRepMax;

// ANA-009: the estimate every set of an exercise's history is ranked by.

it('ANA-009 estimates a one rep max as weight times one plus reps over 30, to 2 decimals', function () {
    expect(EstimatedOneRepMax::kg(60.0, 8))->toBe(76.0)
        ->and(EstimatedOneRepMax::kg(50.0, 10))->toBe(66.67)
        ->and(EstimatedOneRepMax::kg(52.5, 10))->toBe(70.0)
        ->and(EstimatedOneRepMax::kg(105.0, 8))->toBe(133.0);
});

it('ANA-009 reads a missing weight or missing reps as 0', function () {
    expect(EstimatedOneRepMax::kg(null, null))->toBe(0.0)
        ->and(EstimatedOneRepMax::kg(null, 8))->toBe(0.0)
        ->and(EstimatedOneRepMax::kg(60.0, null))->toBe(60.0);
});

it('ANA-009 marks only the first set that reaches the best estimate', function () {
    // Session a2 of rp-clone's test: s9 and s11 both reach 76.0.
    expect(EstimatedOneRepMax::bestIndex([76.0, 74.0, 76.0]))->toBe(0)
        ->and(EstimatedOneRepMax::bestIndex([66.67, 70.0]))->toBe(1)
        ->and(EstimatedOneRepMax::bestIndex([0.0]))->toBe(0);
});

it('ANA-009 treats estimates within a billionth as the same best', function () {
    expect(EstimatedOneRepMax::bestIndex([70.0, 70.0 + 5e-10, 69.0]))->toBe(0)
        ->and(EstimatedOneRepMax::bestIndex([69.9999999, 70.0]))->toBe(1);
});

it('ANA-009 has no best of no sets', function () {
    expect(EstimatedOneRepMax::bestIndex([]))->toBeNull()
        ->and(EstimatedOneRepMax::best([]))->toBe(0.0)
        ->and(EstimatedOneRepMax::best([66.67, 70.0, 12.0]))->toBe(70.0);
});
