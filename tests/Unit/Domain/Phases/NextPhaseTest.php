<?php

use App\Domain\Model\ClosedRange;
use App\Domain\Model\PhaseType;
use App\Domain\Phases\FinalReviewOption;
use App\Domain\Phases\NextPhase;
use App\Domain\Phases\NextPhaseOption;
use App\Domain\Phases\PhaseSummary;
use App\Domain\Phases\PhaseWarning;
use App\Domain\Phases\PhaseWarnings;
use App\Domain\Phases\SequenceStep;

it('PH-030 the summary holds weight change and the signed weekly rate', function () {
    $summary = new PhaseSummary(
        type: PhaseType::FatLoss, startTrendWeightKg: 80, endTrendWeightKg: 76, weeks: 8,
        adherence: 0.9, mesocyclesCompleted: 2,
    );
    expect($summary->weightChangeKg())->toEqualWithDelta(-4, 1e-9)
        ->and($summary->weeklyRatePercent())->toEqualWithDelta(-0.625, 1e-9)
        ->and($summary->adherence)->toBe(0.9)
        ->and($summary->mesocyclesCompleted)->toBe(2);

    $transition = NextPhase::transition($summary);
    expect($transition->summary)->toBe($summary)
        ->and($transition->recommendation)->toEqual([NextPhaseOption::phase(PhaseType::Maintenance), NextPhaseOption::gainFirst()])
        ->and($transition->alternatives)->toBe([PhaseType::FatLoss, PhaseType::MiniCut, PhaseType::MuscleGain]);

    $gain = new PhaseSummary(
        type: PhaseType::MuscleGain, startTrendWeightKg: 70, endTrendWeightKg: 72.8, weeks: 8,
        adherence: 1, mesocyclesCompleted: 2,
    );
    expect($gain->weeklyRatePercent())->toEqualWithDelta(0.5, 1e-9)
        ->and(NextPhase::transition($gain)->alternatives)->toBe([PhaseType::FatLoss, PhaseType::MuscleGain]);
});

it('PH-031 maintenance follows fat loss', function () {
    expect(NextPhase::recommendation(PhaseType::FatLoss)[0])->toEqual(NextPhaseOption::phase(PhaseType::Maintenance))
        ->and(NextPhase::recommendation(PhaseType::MiniCut)[0])->toEqual(NextPhaseOption::phase(PhaseType::Maintenance));
});

it('PH-031 maintenance length after one cut is two thirds', function () {
    $length = NextPhase::maintenanceAfterFatLoss(
        fatLossWeeks: 12, fatLossPhasesInPast12Months: 1, trendStillFallingOnMaintenance: false, mesocycleWeeks: 4,
    );
    expect($length->defaultWeeks)->toBe(8)
        ->and($length->allowedWeeks)->toEqual(new ClosedRange(8, 24));
});

it('PH-031 maintenance length after two cuts is one times', function () {
    $length = NextPhase::maintenanceAfterFatLoss(
        fatLossWeeks: 12, fatLossPhasesInPast12Months: 2, trendStillFallingOnMaintenance: false, mesocycleWeeks: 4,
    );
    expect($length->defaultWeeks)->toBe(12)
        ->and($length->allowedWeeks)->toEqual(new ClosedRange(8, 24));
});

it('PH-031 trend still falling allows a quarter', function () {
    $length = NextPhase::maintenanceAfterFatLoss(
        fatLossWeeks: 12, fatLossPhasesInPast12Months: 1, trendStillFallingOnMaintenance: true, mesocycleWeeks: 4,
    );
    expect($length->allowedWeeks)->toEqual(new ClosedRange(3, 24))
        ->and($length->defaultWeeks)->toBe(8);
});

it('PH-031 default rounds to whole mesocycles and is at least one', function () {
    // 2/3 x 10 = 6.67 weeks = 1.33 five-week mesocycles -> 1.
    $rounded = NextPhase::maintenanceAfterFatLoss(
        fatLossWeeks: 10, fatLossPhasesInPast12Months: 1, trendStillFallingOnMaintenance: false, mesocycleWeeks: 5,
    );
    expect($rounded->defaultWeeks)->toBe(5)
        ->and($rounded->allowedWeeks->contains($rounded->defaultWeeks))->toBeTrue()
        ->and($rounded->allowedWeeks)->toEqual(new ClosedRange(5, 20));

    $tiny = NextPhase::maintenanceAfterFatLoss(
        fatLossWeeks: 3, fatLossPhasesInPast12Months: 1, trendStillFallingOnMaintenance: false, mesocycleWeeks: 5,
    );
    expect($tiny->defaultWeeks)->toBe(5);

    // 2x a 1-week cut is 2 weeks, under the one-mesocycle default, so the range widens to hold the default.
    $short = NextPhase::maintenanceAfterFatLoss(
        fatLossWeeks: 1, fatLossPhasesInPast12Months: 1, trendStillFallingOnMaintenance: false, mesocycleWeeks: 4,
    );
    expect($short->defaultWeeks)->toBe(4)
        ->and($short->allowedWeeks)->toEqual(new ClosedRange(1, 4));
});

it('PH-032 gain first is offered after maintenance', function () {
    expect(NextPhase::recommendation(PhaseType::FatLoss))
        ->toEqual([NextPhaseOption::phase(PhaseType::Maintenance), NextPhaseOption::gainFirst()])
        ->and(NextPhase::gainFirstSequence())->toEqual([
            new SequenceStep(phase: PhaseType::MuscleGain, isResensitization: false, mesocycles: 1),
            new SequenceStep(phase: PhaseType::Maintenance, isResensitization: true, mesocycles: 1),
            new SequenceStep(phase: PhaseType::MuscleGain, isResensitization: false, mesocycles: null),
        ]);
});

it('PH-033 after muscle gain maintenance or mini-cut', function () {
    expect(NextPhase::recommendation(PhaseType::MuscleGain))
        ->toEqual([NextPhaseOption::phase(PhaseType::Maintenance), NextPhaseOption::phase(PhaseType::MiniCut)])
        ->and(NextPhase::maintenanceAfterGainWeeks())->toEqual(new ClosedRange(4, 8));
});

it('PH-034 after maintenance offers fat loss and muscle gain', function () {
    expect(NextPhase::recommendation(PhaseType::Maintenance))
        ->toEqualCanonicalizing([NextPhaseOption::phase(PhaseType::FatLoss), NextPhaseOption::phase(PhaseType::MuscleGain)]);

    foreach ([PhaseType::FatLoss, PhaseType::MiniCut] as $cut) {
        $next = NextPhase::recommendation($cut);
        expect($next)->not->toContainEqual(NextPhaseOption::phase(PhaseType::FatLoss))
            ->and($next)->not->toContainEqual(NextPhaseOption::phase(PhaseType::MiniCut));
    }
});

it('PH-034 warns when maintenance is under sixty percent of the cut', function () {
    expect(PhaseWarnings::shortMaintenanceWarning(maintenanceWeeks: 7, previousFatLossWeeks: 12))
        ->toEqual(PhaseWarning::shortMaintenanceBeforeFatLoss())
        ->and(PhaseWarnings::shortMaintenanceWarning(maintenanceWeeks: 8, previousFatLossWeeks: 12))->toBeNull()
        ->and(PhaseWarnings::shortMaintenanceWarning(maintenanceWeeks: 6, previousFatLossWeeks: 10))->toBeNull()
        ->and(PhaseWarning::shortMaintenanceBeforeFatLoss()->message())->toBe('Maintenance was under 60% of the last fat-loss phase');
});

it('PH-041 final review options', function () {
    expect(NextPhase::finalReviewOptions(PhaseType::FatLoss, currentWeeks: 12))
        ->toEqual([FinalReviewOption::keepGoal(4), FinalReviewOption::chooseNewGoal(), FinalReviewOption::endPlan()])
        ->and(NextPhase::finalReviewOptions(PhaseType::FatLoss, currentWeeks: 16))
        ->toEqual([FinalReviewOption::chooseNewGoal(), FinalReviewOption::endPlan()])
        ->and(NextPhase::finalReviewOptions(PhaseType::Maintenance, currentWeeks: 30))
        ->toEqual([FinalReviewOption::keepGoal(null), FinalReviewOption::chooseNewGoal(), FinalReviewOption::endPlan()])
        ->and(NextPhase::finalReviewOptions(PhaseType::MiniCut, currentWeeks: 5))
        ->toEqual([FinalReviewOption::keepGoal(1), FinalReviewOption::chooseNewGoal(), FinalReviewOption::endPlan()]);
});
