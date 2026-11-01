<?php

namespace App\Domain\Macros;

/** Why a calorie move was refused (NUT-021, NUT-022, NUT-024). */
enum MoveRefusalReason: string
{
    case DayCompleted = 'dayCompleted';
    case OutsideBand = 'outsideBand';
    case BreaksFloor = 'breaksFloor';
    case Invalid = 'invalid';
}
