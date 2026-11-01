<?php

namespace App\Domain\Defaults;

use App\Domain\Defaults\TBL040LoadSteps\LoadType;
use App\Domain\Defaults\TBL040LoadSteps\Step;

/** TBL-040 Load steps by equipment. */
final class TBL040LoadSteps
{
    public static function step(LoadType $type): Step
    {
        return match ($type) {
            LoadType::BarbellOrSmithMachine => new Step(kg: 2.5, lb: 5, microPlatesKg: 1.25, microPlatesLb: 2.5),
            LoadType::Dumbbell => new Step(kg: 2, lb: 5, microPlatesKg: null, microPlatesLb: null),
            LoadType::MachineOrCable => new Step(kg: 2.5, lb: 5, microPlatesKg: null, microPlatesLb: null),
            LoadType::Bodyweight => new Step(kg: null, lb: null, microPlatesKg: null, microPlatesLb: null),
            LoadType::BodyweightPlusLoad => new Step(kg: 1.25, lb: 2.5, microPlatesKg: null, microPlatesLb: null),
        };
    }
}
