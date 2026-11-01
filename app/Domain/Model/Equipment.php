<?php

namespace App\Domain\Model;

/** What an exercise is done with (EX-003): nine equipment values. */
enum Equipment: string
{
    case Barbell = 'barbell';
    case EzBar = 'ezBar';
    case TrapBar = 'trapBar';
    case Dumbbell = 'dumbbell';
    case Kettlebell = 'kettlebell';
    case Machine = 'machine';
    case Cable = 'cable';
    case SmithMachine = 'smithMachine';
    case Bodyweight = 'bodyweight';

    public function title(): string
    {
        return match ($this) {
            self::Barbell => 'Barbell',
            self::EzBar => 'EZ bar',
            self::TrapBar => 'Trap bar',
            self::Dumbbell => 'Dumbbell',
            self::Kettlebell => 'Kettlebell',
            self::Machine => 'Machine',
            self::Cable => 'Cable',
            self::SmithMachine => 'Smith machine',
            self::Bodyweight => 'Bodyweight',
        };
    }
}
