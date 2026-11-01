<?php

use App\Domain\Defaults\TBL022FeedbackScores;
use App\Domain\Model\Pump;
use App\Domain\Model\Soreness;
use App\Domain\Model\VolumeFeedback;

it('TBL-022 soreness points', function (Soreness $soreness, float $points) {
    expect(TBL022FeedbackScores::sorenessPoints($soreness))->toBe($points);
})->with([
    [Soreness::NeverGotSore, 1.0],
    [Soreness::HealedAWhileAgo, 0.5],
    [Soreness::HealedJustOnTime, 0.0],
    [Soreness::StillSore, -1.0],
]);

it('TBL-022 pump points', function (Pump $pump, float $points) {
    expect(TBL022FeedbackScores::pumpPoints($pump))->toBe($points);
})->with([
    [Pump::Low, 1.0],
    [Pump::Moderate, 0.0],
    [Pump::Amazing, -0.5],
]);

it('TBL-022 volume points, none where PROG-012 and PROG-013 override the score', function (VolumeFeedback $volume, ?float $points) {
    expect(TBL022FeedbackScores::volumePoints($volume))->toBe($points);
})->with([
    [VolumeFeedback::NotEnough, 1.0],
    [VolumeFeedback::JustRight, 0.0],
    [VolumeFeedback::PushedMyLimits, null],
    [VolumeFeedback::TooMuch, null],
]);

it('TBL-022 positive soreness counts only with a weak pump or not enough volume', function () {
    expect(TBL022FeedbackScores::POSITIVE_SORENESS_COUNTING_ONLY_WITH_LOW_PUMP_OR_NOT_ENOUGH_VOLUME)
        ->toBe([Soreness::NeverGotSore, Soreness::HealedAWhileAgo]);
});
