<?php

namespace App\Domain\Model;

use App\Domain\Defaults\TBL040LoadSteps\LoadType;

/** How an exercise's weight is entered and stepped (EX-005): seven load types. */
enum ExerciseLoadType: string
{
    case Barbell = 'barbell';
    case Dumbbell = 'dumbbell';
    case Machine = 'machine';
    case Cable = 'cable';
    case Bodyweight = 'bodyweight';
    case BodyweightPlusLoad = 'bodyweightPlusLoad';
    case SmithMachine = 'smithMachine';

    public function title(): string
    {
        return match ($this) {
            self::Barbell => 'Barbell',
            self::Dumbbell => 'Dumbbell',
            self::Machine => 'Machine',
            self::Cable => 'Cable',
            self::Bodyweight => 'Bodyweight',
            self::BodyweightPlusLoad => 'Bodyweight plus load',
            self::SmithMachine => 'Smith machine',
        };
    }

    /** The TBL-040 row that gives this load type its default step. */
    public function tbl040(): LoadType
    {
        return match ($this) {
            self::Barbell, self::SmithMachine => LoadType::BarbellOrSmithMachine,
            self::Dumbbell => LoadType::Dumbbell,
            self::Machine, self::Cable => LoadType::MachineOrCable,
            self::Bodyweight => LoadType::Bodyweight,
            self::BodyweightPlusLoad => LoadType::BodyweightPlusLoad,
        };
    }
}
