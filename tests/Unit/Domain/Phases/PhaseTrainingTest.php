<?php

use App\Domain\Defaults\TBL012TrainingDefaults;
use App\Domain\Defaults\TBL012TrainingDefaults\Column;
use App\Domain\Defaults\TBL012TrainingDefaults\LandmarkOffset;
use App\Domain\Defaults\TBL012TrainingDefaults\PriorityPreset;
use App\Domain\Defaults\TBL012TrainingDefaults\RepRangeBias;
use App\Domain\Defaults\TBL012TrainingDefaults\Row;
use App\Domain\Defaults\TBL012TrainingDefaults\TemplateFit;
use App\Domain\Model\ClosedRange;
use App\Domain\Model\Mesocycle;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Priority;
use App\Domain\Phases\PhaseTraining;

function assertTbl012Row(
    Row $row, PriorityPreset $preset, TemplateFit $fit, LandmarkOffset $start, LandmarkOffset $ceiling,
    int $setStep, ?float $loadStep, RepRangeBias $reps, int $minFrequency,
): void {
    expect($row->priorityPreset)->toBe($preset)
        ->and($row->templateFit)->toBe($fit)
        ->and($row->startVolume)->toEqual($start)
        ->and($row->ceiling)->toEqual($ceiling)
        ->and($row->maxSetsAddedPerSessionPerWeek)->toBe($setStep)
        ->and($row->weeklyLoadStepPercent)->toBe($loadStep)
        ->and($row->repRangeBias)->toEqual($reps)
        ->and($row->minimumFrequencyPerWeek)->toBe($minFrequency);
}

/**
 * The distinct values of a priority map.
 *
 * @param  array<string, Priority>  $priorities
 * @return list<Priority>
 */
function distinctPriorities(array $priorities): array
{
    return array_values(array_unique($priorities, SORT_REGULAR));
}

it('PH-008 every phase type maps to its TBL-012 row', function () {
    assertTbl012Row(
        PhaseTraining::defaults(PhaseType::FatLoss), PriorityPreset::AllEmphasize, TemplateFit::FullBody,
        LandmarkOffset::mev(2), LandmarkOffset::mrv(-2),
        setStep: 1, loadStep: 1, reps: RepRangeBias::ranges(new ClosedRange(10, 20), new ClosedRange(20, 30)), minFrequency: 2,
    );
    assertTbl012Row(
        PhaseTraining::defaults(PhaseType::MiniCut), PriorityPreset::AllEmphasize, TemplateFit::FullBody,
        LandmarkOffset::mev(0), LandmarkOffset::mev(2),
        setStep: 1, loadStep: 1, reps: RepRangeBias::ranges(new ClosedRange(10, 20)), minFrequency: 2,
    );
    assertTbl012Row(
        PhaseTraining::defaults(PhaseType::Maintenance, isResensitization: true), PriorityPreset::AllMaintain,
        TemplateFit::FullBodyTwoToThreeDays, LandmarkOffset::mv(), LandmarkOffset::mv(),
        setStep: 0, loadStep: null, reps: RepRangeBias::ranges(new ClosedRange(5, 10)), minFrequency: 1,
    );
    assertTbl012Row(
        PhaseTraining::defaults(PhaseType::Maintenance, isResensitization: false), PriorityPreset::AllGrow, TemplateFit::Any,
        LandmarkOffset::mev(0), LandmarkOffset::mrv(0),
        setStep: 2, loadStep: 2, reps: RepRangeBias::mixed(), minFrequency: 2,
    );
    assertTbl012Row(
        PhaseTraining::defaults(PhaseType::MuscleGain), PriorityPreset::UpToTwoEmphasizeRestGrowOrMaintain,
        TemplateFit::Specialisation, LandmarkOffset::mev(-1), LandmarkOffset::mrv(2),
        setStep: 2, loadStep: 2, reps: RepRangeBias::mixedMoreFiveToTen(), minFrequency: 2,
    );
});

it('PH-008 default priorities follow the preset', function () {
    $all = array_map(fn (MuscleGroup $muscle) => $muscle->value, MuscleGroup::cases());
    $emphasize = PhaseTraining::defaultPriorities(Column::FatLoss);
    expect(array_keys($emphasize))->toEqualCanonicalizing($all)
        ->and(distinctPriorities($emphasize))->toBe([Priority::Emphasize])
        ->and(distinctPriorities(PhaseTraining::defaultPriorities(Column::MaintenanceResensitization)))->toBe([Priority::Maintain])
        ->and(distinctPriorities(PhaseTraining::defaultPriorities(Column::MaintenanceBalanced)))->toBe([Priority::Grow])
        ->and(distinctPriorities(PhaseTraining::defaultPriorities(Column::MuscleGain)))->toBe([Priority::Grow])
        ->and(PhaseTraining::defaultPriorities(Column::MiniCut, [MuscleGroup::Chest, MuscleGroup::Back]))
        ->toBe(['chest' => Priority::Emphasize, 'back' => Priority::Emphasize]);
});

it('PH-009 progression column switches from the next week', function () {
    $meso = new Mesocycle(
        id: 'meso', phase: PhaseType::FatLoss, weeks: 5, sessions: [], musclePriorities: ['chest' => Priority::Grow],
    );
    $before = clone $meso;
    $column = fn (int $week, PhaseType $type) => PhaseTraining::progressionColumn($meso, $week, $type, changeWeek: 2);

    expect($column(1, PhaseType::MuscleGain))->toBe(Column::FatLoss)
        ->and($column(2, PhaseType::MuscleGain))->toBe(Column::FatLoss)
        ->and($column(3, PhaseType::MuscleGain))->toBe(Column::MuscleGain)
        ->and($column(5, PhaseType::MuscleGain))->toBe(Column::MuscleGain)
        ->and($column(3, PhaseType::Maintenance))->toBe(Column::MaintenanceBalanced)
        ->and($meso)->toEqual($before);
});

it('PH-009 next mesocycle gets the new phase defaults', function () {
    $next = PhaseTraining::mesocycleDefaults(PhaseType::MiniCut);
    expect($next->column)->toBe(Column::MiniCut)
        ->and($next->row)->toEqual(TBL012TrainingDefaults::row(Column::MiniCut))
        ->and(distinctPriorities($next->priorities))->toBe([Priority::Emphasize])
        ->and($next->priorities)->toHaveCount(count(MuscleGroup::cases()));
});

it('PH-036 maintenance after fat loss defaults to resensitization', function () {
    expect(PhaseTraining::maintenanceDefaultsToResensitization(PhaseType::FatLoss))->toBeTrue()
        ->and(PhaseTraining::maintenanceDefaultsToResensitization(PhaseType::MiniCut))->toBeTrue()
        ->and(PhaseTraining::maintenanceDefaultsToResensitization(PhaseType::MuscleGain))->toBeFalse()
        ->and(PhaseTraining::maintenanceDefaultsToResensitization(null))->toBeFalse();

    $resens = PhaseTraining::mesocycleDefaults(PhaseType::Maintenance, isResensitization: true);
    expect($resens->column)->toBe(Column::MaintenanceResensitization)
        ->and(distinctPriorities($resens->priorities))->toBe([Priority::Maintain])
        ->and($resens->row->startVolume)->toEqual(LandmarkOffset::mv())
        ->and($resens->row->ceiling)->toEqual(LandmarkOffset::mv())
        ->and(PhaseTraining::resensitizationSessionsPerWeek())->toEqual(new ClosedRange(2, 3))
        ->and(PhaseTraining::resensitizationSessionsPerMusclePerWeek())->toEqual(new ClosedRange(1, 2))
        ->and(PhaseTraining::resensitizationWeeks())->toEqual(new ClosedRange(3, 4))
        ->and(PhaseTraining::defaultResensitizationWeeks())->toBe(4)
        // The user may pick balanced instead.
        ->and(PhaseTraining::mesocycleDefaults(PhaseType::Maintenance)->column)->toBe(Column::MaintenanceBalanced);
});

it('PH-039 mini-cut defaults all emphasize ceiling MEV plus two', function () {
    expect(PhaseTraining::defaults(PhaseType::MiniCut)->priorityPreset)->toBe(PriorityPreset::AllEmphasize)
        ->and(PhaseTraining::defaults(PhaseType::MiniCut)->ceiling)->toEqual(LandmarkOffset::mev(2))
        ->and(distinctPriorities(PhaseTraining::defaultPriorities(Column::MiniCut)))->toBe([Priority::Emphasize]);
});
