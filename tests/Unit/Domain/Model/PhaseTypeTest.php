<?php

use App\Domain\Model\PhaseType;

it('PLAN-009 treats mini-cut as fat loss', function () {
    expect(PhaseType::FatLoss->isFatLoss())->toBeTrue()
        ->and(PhaseType::MiniCut->isFatLoss())->toBeTrue()
        ->and(PhaseType::Maintenance->isFatLoss())->toBeFalse()
        ->and(PhaseType::MuscleGain->isFatLoss())->toBeFalse();
});
