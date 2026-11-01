<?php

use App\Domain\Defaults\TBL010Phases;
use App\Domain\Defaults\TBL010Phases\RatePreset;
use App\Domain\Model\ClosedRange;
use App\Domain\Model\ExperienceLevel;
use App\Domain\Model\Phase;
use App\Domain\Model\PhaseType;

it('TBL-010 fat loss', function () {
    $row = TBL010Phases::row(PhaseType::FatLoss);

    expect($row->rateRangePercentPerWeek)->toEqual(new ClosedRange(-1.0, -0.25))
        ->and($row->presets)->toEqual([
            new RatePreset('Highest success', -0.75),
            new RatePreset('Slow & steady', -0.5),
            new RatePreset('Custom', null),
        ])
        ->and($row->minWeeks)->toBe(2)
        ->and($row->maxWeeks)->toBe(16)
        ->and($row->recommendedWeeks)->toEqual(new ClosedRange(6, 12));
});

it('TBL-010 muscle gain', function () {
    $row = TBL010Phases::row(PhaseType::MuscleGain);

    expect($row->rateRangePercentPerWeek)->toEqual(new ClosedRange(0.125, 0.5))
        ->and($row->presets)->toEqual([new RatePreset('Custom', null)])
        ->and($row->minWeeks)->toBe(4)
        ->and($row->maxWeeks)->toBe(16)
        ->and($row->recommendedWeeks)->toEqual(new ClosedRange(8, 16))
        ->and(TBL010Phases::muscleGainRatePercentPerWeek(ExperienceLevel::Beginner))->toBe(0.5)
        ->and(TBL010Phases::muscleGainRatePercentPerWeek(ExperienceLevel::Intermediate))->toBe(0.375)
        ->and(TBL010Phases::muscleGainRatePercentPerWeek(ExperienceLevel::Advanced))->toBe(0.25)
        ->and(TBL010Phases::muscleGainBookRecommendedWeeks())->toEqual(new ClosedRange(6, 16));
});

it('TBL-010 maintenance', function () {
    $row = TBL010Phases::row(PhaseType::Maintenance);

    expect($row->rateRangePercentPerWeek)->toEqual(new ClosedRange(-0.25, 0.25))
        ->and($row->presets)->toEqual([new RatePreset('Hold', 0.0)])
        ->and($row->minWeeks)->toBe(2)
        ->and($row->maxWeeks)->toBeNull()
        ->and($row->recommendedWeeks)->toBeNull()
        ->and(TBL010Phases::maintenanceAfterCutLengthFactor())->toEqual(new ClosedRange(2.0 / 3.0, 1.0));
});

it('TBL-010 mini-cut', function () {
    $row = TBL010Phases::row(PhaseType::MiniCut);

    expect($row->rateRangePercentPerWeek)->toEqual(new ClosedRange(-1.0, -0.75))
        ->and($row->presets)->toEqual([new RatePreset('Default', -1.0)])
        ->and($row->minWeeks)->toBe(2)
        ->and($row->maxWeeks)->toBe(6)
        ->and($row->recommendedWeeks)->toBeNull()
        ->and(TBL010Phases::MINIMUM_GAIN_TO_MINI_CUT_LENGTH_RATIO)->toBe(2.0);
});

it('TBL-010 preset rates lie inside their own phase range', function (PhaseType $type, string $presetName) {
    $day = new DateTimeImmutable('@1700000000');
    $row = TBL010Phases::row($type);
    $presets = array_values(array_filter($row->presets, fn (RatePreset $preset) => $preset->name === $presetName));
    $phase = new Phase(id: 'p', type: $type, start: $day, end: $day, targetRatePercentPerWeek: $presets[0]->ratePercentPerWeek ?? NAN);

    expect($row->rateRangePercentPerWeek->contains($phase->targetRatePercentPerWeek))->toBeTrue()
        ->and($phase->targetRatePercentPerWeek)->toBeLessThan(0);
})->with([
    'fat loss' => [PhaseType::FatLoss, 'Highest success'],
    'mini-cut' => [PhaseType::MiniCut, 'Default'],
]);

it('TBL-010 muscle-gain rates by experience lie inside the muscle-gain range', function (ExperienceLevel $experience) {
    expect(TBL010Phases::muscleGain()->rateRangePercentPerWeek->contains(TBL010Phases::muscleGainRatePercentPerWeek($experience)))
        ->toBeTrue();
})->with(ExperienceLevel::cases());

it('TBL-010 fat-loss limits', function () {
    expect(TBL010Phases::MINI_CUT_SUGGESTED_BELOW_FAT_LOSS_WEEKS)->toBe(6)
        ->and(TBL010Phases::FAT_LOSS_WARN_ABOVE_WEEKS)->toBe(12)
        ->and(TBL010Phases::MAX_FAT_LOSS_PERCENT_OF_START_BODYWEIGHT)->toBe(10.0);
});
