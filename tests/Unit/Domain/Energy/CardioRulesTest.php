<?php

use App\Domain\Energy\EnergyRules;
use App\Domain\Model\PhaseType;

it('SCH-062 four hard sessions a week do not warn', function () {
    expect(EnergyRules::CARDIO_HARD_SESSIONS_PER_WEEK)->toBe(4)
        ->and(EnergyRules::cardioWarning(PhaseType::FatLoss, hardOtherActivitiesInWeek: 4))->toBeNull()
        ->and(EnergyRules::cardioWarning(PhaseType::FatLoss, hardOtherActivitiesInWeek: 0))->toBeNull();
});

it('SCH-062 the fifth hard session in fat loss warns', function () {
    expect(EnergyRules::cardioWarning(PhaseType::FatLoss, hardOtherActivitiesInWeek: 5))
        ->toBe('Too much cardio hurts lifting recovery. That makes 5 hard sessions this week; more than 4 is a lot during fat loss.')
        ->and(EnergyRules::cardioWarning(PhaseType::FatLoss, hardOtherActivitiesInWeek: 7))->not->toBeNull();
});

it('SCH-062 PLAN-009 a mini-cut counts as fat loss', function () {
    expect(EnergyRules::cardioWarning(PhaseType::MiniCut, hardOtherActivitiesInWeek: 5))->not->toBeNull();
});

it('SCH-062 other phases never warn', function (PhaseType $phase) {
    expect(EnergyRules::cardioWarning($phase, hardOtherActivitiesInWeek: 9))->toBeNull();
})->with([PhaseType::Maintenance, PhaseType::MuscleGain]);
