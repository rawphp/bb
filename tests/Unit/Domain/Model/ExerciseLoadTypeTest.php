<?php

use App\Domain\Defaults\TBL040LoadSteps\LoadType;
use App\Domain\Model\Equipment;
use App\Domain\Model\ExerciseLoadType;

it('EX-005 has the seven load types with titles', function () {
    expect(array_map(fn (ExerciseLoadType $type) => $type->title(), ExerciseLoadType::cases()))
        ->toBe(['Barbell', 'Dumbbell', 'Machine', 'Cable', 'Bodyweight', 'Bodyweight plus load', 'Smith machine']);
});

it('EX-005 maps every load type to a TBL-040 row', function (ExerciseLoadType $type, LoadType $row) {
    expect($type->tbl040())->toBe($row);
})->with([
    [ExerciseLoadType::Barbell, LoadType::BarbellOrSmithMachine],
    [ExerciseLoadType::SmithMachine, LoadType::BarbellOrSmithMachine],
    [ExerciseLoadType::Dumbbell, LoadType::Dumbbell],
    [ExerciseLoadType::Machine, LoadType::MachineOrCable],
    [ExerciseLoadType::Cable, LoadType::MachineOrCable],
    [ExerciseLoadType::Bodyweight, LoadType::Bodyweight],
    [ExerciseLoadType::BodyweightPlusLoad, LoadType::BodyweightPlusLoad],
]);

it('EX-003 has the nine equipments with titles', function () {
    expect(array_map(fn (Equipment $equipment) => $equipment->title(), Equipment::cases()))
        ->toBe(['Barbell', 'EZ bar', 'Trap bar', 'Dumbbell', 'Kettlebell', 'Machine', 'Cable', 'Smith machine', 'Bodyweight']);
});
