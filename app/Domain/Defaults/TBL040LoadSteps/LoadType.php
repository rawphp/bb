<?php

namespace App\Domain\Defaults\TBL040LoadSteps;

/** The TBL-040 rows. ExerciseLoadType::tbl040() maps each exercise load type to one. */
enum LoadType: string
{
    case BarbellOrSmithMachine = 'barbellOrSmithMachine';
    case Dumbbell = 'dumbbell';
    case MachineOrCable = 'machineOrCable';
    case Bodyweight = 'bodyweight';
    case BodyweightPlusLoad = 'bodyweightPlusLoad';
}
