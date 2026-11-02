<?php

namespace App\Domain\Retarget\WeekdayChoice;

enum Kind: string
{
    /** Rebuild the remaining weeks for the new day count. */
    case Restructure = 'restructure';

    /** Keep every session; the mesocycle then runs longer. */
    case KeepAllSessions = 'keep_all_sessions';
}
