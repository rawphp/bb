<?php

use App\Domain\Defaults\TBL040LoadSteps;
use App\Domain\Defaults\TBL040LoadSteps\LoadType;
use App\Domain\Defaults\TBL040LoadSteps\Step;
use App\Domain\Model\WeightUnit;

it('TBL-040 load steps by equipment', function (LoadType $type, Step $step) {
    expect(TBL040LoadSteps::step($type))->toEqual($step);
})->with([
    'barbell, smith machine' => [LoadType::BarbellOrSmithMachine, new Step(kg: 2.5, lb: 5, microPlatesKg: 1.25, microPlatesLb: 2.5)],
    'dumbbell' => [LoadType::Dumbbell, new Step(kg: 2, lb: 5, microPlatesKg: null, microPlatesLb: null)],
    'machine, cable' => [LoadType::MachineOrCable, new Step(kg: 2.5, lb: 5, microPlatesKg: null, microPlatesLb: null)],
    'bodyweight, reps only' => [LoadType::Bodyweight, new Step(kg: null, lb: null, microPlatesKg: null, microPlatesLb: null)],
    'bodyweight plus load' => [LoadType::BodyweightPlusLoad, new Step(kg: 1.25, lb: 2.5, microPlatesKg: null, microPlatesLb: null)],
]);

it('TBL-040 lists the load types in order', function () {
    expect(LoadType::cases())->toBe([
        LoadType::BarbellOrSmithMachine, LoadType::Dumbbell, LoadType::MachineOrCable, LoadType::Bodyweight, LoadType::BodyweightPlusLoad,
    ]);
});

it('TBL-040 gives the step in a unit, using micro plates only where the row has them', function (LoadType $type, WeightUnit $unit, bool $micro, ?float $value) {
    expect(TBL040LoadSteps::step($type)->value($unit, $micro))->toBe($value);
})->with([
    [LoadType::BarbellOrSmithMachine, WeightUnit::Kg, false, 2.5],
    [LoadType::BarbellOrSmithMachine, WeightUnit::Kg, true, 1.25],
    [LoadType::BarbellOrSmithMachine, WeightUnit::Lb, false, 5.0],
    [LoadType::BarbellOrSmithMachine, WeightUnit::Lb, true, 2.5],
    'no micro step falls back to the regular step' => [LoadType::Dumbbell, WeightUnit::Kg, true, 2.0],
    'reps only' => [LoadType::Bodyweight, WeightUnit::Lb, true, null],
]);
