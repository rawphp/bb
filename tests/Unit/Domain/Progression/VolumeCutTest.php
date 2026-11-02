<?php

use App\Domain\Model\VolumeFeedback;
use App\Domain\Progression\VolumeCut;

it('PROG-055 cuts sets by one third, rounded to the nearest set', function () {
    expect(VolumeCut::cutSets(3, []))->toBe(2)
        ->and(VolumeCut::cutSets(4, [VolumeFeedback::JustRight]))->toBe(3)
        ->and(VolumeCut::cutSets(5, [VolumeFeedback::TooMuch]))->toBe(3)
        ->and(VolumeCut::cutSets(6, [VolumeFeedback::PushedMyLimits, VolumeFeedback::TooMuch]))->toBe(4)
        ->and(VolumeCut::cutSets(9, [VolumeFeedback::TooMuch, VolumeFeedback::JustRight]))->toBe(6);
});

it('PROG-055 cuts by half after Too much twice running', function () {
    $twice = [VolumeFeedback::JustRight, VolumeFeedback::TooMuch, VolumeFeedback::TooMuch];
    expect(VolumeCut::cutSets(4, $twice))->toBe(2)
        ->and(VolumeCut::cutSets(6, $twice))->toBe(3)
        ->and(VolumeCut::cutSets(5, $twice))->toBe(2)
        ->and(VolumeCut::cutSets(7, $twice))->toBe(3)
        // Twice, but not running: one third.
        ->and(VolumeCut::cutSets(6, [VolumeFeedback::TooMuch, VolumeFeedback::JustRight, VolumeFeedback::TooMuch]))->toBe(4);
});

it('PROG-055 keeps at least one set', function () {
    expect(VolumeCut::cutSets(1, [VolumeFeedback::TooMuch, VolumeFeedback::TooMuch]))->toBe(1)
        ->and(VolumeCut::cutSets(1, []))->toBe(1)
        ->and(VolumeCut::cutSets(0, []))->toBe(0);
});

it('PROG-055 adds one set per week until feedback finds the ceiling', function () {
    expect(VolumeCut::rebuiltSets(3, null))->toBe(4)
        ->and(VolumeCut::rebuiltSets(4, VolumeFeedback::JustRight))->toBe(5)
        ->and(VolumeCut::rebuiltSets(4, VolumeFeedback::NotEnough))->toBe(5)
        // The ceiling: rebuilding stops and the normal set rules take over.
        ->and(VolumeCut::rebuiltSets(5, VolumeFeedback::PushedMyLimits))->toBeNull()
        ->and(VolumeCut::rebuiltSets(5, VolumeFeedback::TooMuch))->toBeNull();
});
