<?php

namespace App\Domain\Defaults;

use App\Domain\Defaults\TBL012TrainingDefaults\Column;
use App\Domain\Defaults\TBL012TrainingDefaults\DeloadCalories;
use App\Domain\Defaults\TBL012TrainingDefaults\LandmarkOffset;
use App\Domain\Defaults\TBL012TrainingDefaults\PriorityPreset;
use App\Domain\Defaults\TBL012TrainingDefaults\RepRangeBias;
use App\Domain\Defaults\TBL012TrainingDefaults\Row;
use App\Domain\Defaults\TBL012TrainingDefaults\TemplateFit;
use App\Domain\Model\ClosedRange;

/** TBL-012 Training defaults by phase. Five columns: maintenance is split into resensitization and balanced. */
final class TBL012TrainingDefaults
{
    public static function row(Column $column): Row
    {
        return match ($column) {
            Column::FatLoss => new Row(
                priorityPreset: PriorityPreset::AllEmphasize, templateFit: TemplateFit::FullBody,
                startVolume: LandmarkOffset::mev(2), ceiling: LandmarkOffset::mrv(-2), maxSetsAddedPerSessionPerWeek: 1,
                weeklyLoadStepPercent: 1, loadStepUsesSmallestPlate: true,
                copyLoadRisePercent: 2.5, copyLoadRiseAdvancedPercent: new ClosedRange(1.0, 1.25),
                repRangeBias: RepRangeBias::ranges(new ClosedRange(10, 20), new ClosedRange(20, 30)),
                minimumFrequencyPerWeek: 2, deloadCalories: DeloadCalories::Maintenance,
            ),
            Column::MiniCut => new Row(
                priorityPreset: PriorityPreset::AllEmphasize, templateFit: TemplateFit::FullBody,
                startVolume: LandmarkOffset::mev(0), ceiling: LandmarkOffset::mev(2), maxSetsAddedPerSessionPerWeek: 1,
                weeklyLoadStepPercent: 1, loadStepUsesSmallestPlate: false,
                copyLoadRisePercent: 2.5, copyLoadRiseAdvancedPercent: new ClosedRange(1.0, 1.25),
                repRangeBias: RepRangeBias::ranges(new ClosedRange(10, 20)),
                minimumFrequencyPerWeek: 2, deloadCalories: DeloadCalories::Maintenance,
            ),
            Column::MaintenanceResensitization => new Row(
                priorityPreset: PriorityPreset::AllMaintain, templateFit: TemplateFit::FullBodyTwoToThreeDays,
                startVolume: LandmarkOffset::mv(), ceiling: LandmarkOffset::mv(), maxSetsAddedPerSessionPerWeek: 0,
                weeklyLoadStepPercent: null, loadStepUsesSmallestPlate: false,
                copyLoadRisePercent: 0, copyLoadRiseAdvancedPercent: new ClosedRange(0.0, 0.0),
                repRangeBias: RepRangeBias::ranges(new ClosedRange(5, 10)),
                minimumFrequencyPerWeek: 1, deloadCalories: DeloadCalories::Maintenance,
            ),
            Column::MaintenanceBalanced => new Row(
                priorityPreset: PriorityPreset::AllGrow, templateFit: TemplateFit::Any,
                startVolume: LandmarkOffset::mev(0), ceiling: LandmarkOffset::mrv(0), maxSetsAddedPerSessionPerWeek: 2,
                weeklyLoadStepPercent: 2, loadStepUsesSmallestPlate: false,
                copyLoadRisePercent: 5, copyLoadRiseAdvancedPercent: new ClosedRange(2.0, 2.5),
                repRangeBias: RepRangeBias::mixed(),
                minimumFrequencyPerWeek: 2, deloadCalories: DeloadCalories::Maintenance,
            ),
            Column::MuscleGain => new Row(
                priorityPreset: PriorityPreset::UpToTwoEmphasizeRestGrowOrMaintain, templateFit: TemplateFit::Specialisation,
                startVolume: LandmarkOffset::mev(-1), ceiling: LandmarkOffset::mrv(2), maxSetsAddedPerSessionPerWeek: 2,
                weeklyLoadStepPercent: 2, loadStepUsesSmallestPlate: false,
                copyLoadRisePercent: 5, copyLoadRiseAdvancedPercent: new ClosedRange(2.0, 2.5),
                repRangeBias: RepRangeBias::mixedMoreFiveToTen(),
                minimumFrequencyPerWeek: 2, deloadCalories: DeloadCalories::Maintenance,
            ),
        };
    }
}
