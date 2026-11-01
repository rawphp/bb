<?php

namespace App\Enums;

enum Equipment: string
{
    case Barbell = 'barbell';
    case EzBar = 'ez_bar';
    case TrapBar = 'trap_bar';
    case Dumbbell = 'dumbbell';
    case Kettlebell = 'kettlebell';
    case Machine = 'machine';
    case Cable = 'cable';
    case SmithMachine = 'smith_machine';
    case Bodyweight = 'bodyweight';
}
