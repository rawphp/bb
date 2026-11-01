<?php

namespace App\Enums;

enum LoadType: string
{
    case Barbell = 'barbell';
    case Dumbbell = 'dumbbell';
    case Machine = 'machine';
    case Cable = 'cable';
    case Bodyweight = 'bodyweight';
    case BodyweightPlusLoad = 'bodyweight_plus_load';
    case SmithMachine = 'smith_machine';
}
