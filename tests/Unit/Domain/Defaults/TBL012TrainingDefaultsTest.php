<?php

use App\Domain\Defaults\TBL012TrainingDefaults;
use App\Domain\Defaults\TBL012TrainingDefaults\Column;
use App\Domain\Defaults\TBL012TrainingDefaults\DeloadCalories;
use App\Domain\Defaults\TBL012TrainingDefaults\LandmarkOffset;
use App\Domain\Defaults\TBL012TrainingDefaults\PriorityPreset;
use App\Domain\Defaults\TBL012TrainingDefaults\RepRangeBias;
use App\Domain\Defaults\TBL012TrainingDefaults\Row;
use App\Domain\Defaults\TBL012TrainingDefaults\TemplateFit;
use App\Domain\Model\ClosedRange;
use App\Domain\Model\PhaseType;

/**
 * One TBL-012 row across the five columns, in column order.
 *
 * @return list<mixed>
 */
function tbl012(Closure $field): array
{
    return array_map(fn (Column $column) => $field(TBL012TrainingDefaults::row($column)), Column::cases());
}

it('TBL-012 lists the columns in order', function () {
    expect(Column::cases())->toBe([
        Column::FatLoss, Column::MiniCut, Column::MaintenanceResensitization, Column::MaintenanceBalanced, Column::MuscleGain,
    ]);
});

it('TBL-012 picks the column from the phase and the resensitization flag', function (PhaseType $phase, bool $resensitization, Column $column) {
    expect(Column::for($phase, $resensitization))->toBe($column);
})->with([
    [PhaseType::FatLoss, false, Column::FatLoss],
    [PhaseType::MiniCut, false, Column::MiniCut],
    [PhaseType::MuscleGain, false, Column::MuscleGain],
    [PhaseType::Maintenance, false, Column::MaintenanceBalanced],
    [PhaseType::Maintenance, true, Column::MaintenanceResensitization],
    'the flag only matters for maintenance' => [PhaseType::FatLoss, true, Column::FatLoss],
]);

it('TBL-012 priority preset', function () {
    expect(tbl012(fn (Row $row) => $row->priorityPreset))->toBe([
        PriorityPreset::AllEmphasize, PriorityPreset::AllEmphasize, PriorityPreset::AllMaintain,
        PriorityPreset::AllGrow, PriorityPreset::UpToTwoEmphasizeRestGrowOrMaintain,
    ]);
});

it('TBL-012 template fit', function () {
    expect(tbl012(fn (Row $row) => $row->templateFit))->toBe([
        TemplateFit::FullBody, TemplateFit::FullBody, TemplateFit::FullBodyTwoToThreeDays, TemplateFit::Any, TemplateFit::Specialisation,
    ]);
});

it('TBL-012 start volume', function () {
    expect(tbl012(fn (Row $row) => $row->startVolume))->toEqual([
        LandmarkOffset::mev(2), LandmarkOffset::mev(0), LandmarkOffset::mv(), LandmarkOffset::mev(0), LandmarkOffset::mev(-1),
    ]);
});

it('TBL-012 ceiling', function () {
    expect(tbl012(fn (Row $row) => $row->ceiling))->toEqual([
        LandmarkOffset::mrv(-2), LandmarkOffset::mev(2), LandmarkOffset::mv(), LandmarkOffset::mrv(0), LandmarkOffset::mrv(2),
    ]);
});

it('TBL-012 max sets added per session per week', function () {
    expect(tbl012(fn (Row $row) => $row->maxSetsAddedPerSessionPerWeek))->toBe([1, 1, 0, 2, 2]);
});

it('TBL-012 weekly load step', function () {
    expect(tbl012(fn (Row $row) => $row->weeklyLoadStepPercent))->toBe([1.0, 1.0, null, 2.0, 2.0])
        ->and(tbl012(fn (Row $row) => $row->loadStepUsesSmallestPlate))->toBe([true, false, false, false, false]);
});

it('TBL-012 copy load rise over old week 1', function () {
    expect(tbl012(fn (Row $row) => $row->copyLoadRisePercent))->toBe([2.5, 2.5, 0.0, 5.0, 5.0])
        ->and(tbl012(fn (Row $row) => $row->copyLoadRiseAdvancedPercent))->toEqual([
            new ClosedRange(1.0, 1.25), new ClosedRange(1.0, 1.25), new ClosedRange(0.0, 0.0),
            new ClosedRange(2.0, 2.5), new ClosedRange(2.0, 2.5),
        ]);
});

it('TBL-012 rep-range bias', function () {
    expect(tbl012(fn (Row $row) => $row->repRangeBias))->toEqual([
        RepRangeBias::ranges(new ClosedRange(10, 20), new ClosedRange(20, 30)),
        RepRangeBias::ranges(new ClosedRange(10, 20)),
        RepRangeBias::ranges(new ClosedRange(5, 10)),
        RepRangeBias::mixed(),
        RepRangeBias::mixedMoreFiveToTen(),
    ]);
});

it('TBL-012 minimum frequency per muscle', function () {
    expect(tbl012(fn (Row $row) => $row->minimumFrequencyPerWeek))->toBe([2, 2, 1, 2, 2]);
});

it('TBL-012 deload calories', function () {
    expect(tbl012(fn (Row $row) => $row->deloadCalories))->toBe(array_fill(0, 5, DeloadCalories::Maintenance));
});
