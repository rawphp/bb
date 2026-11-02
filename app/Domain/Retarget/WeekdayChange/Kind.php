<?php

namespace App\Domain\Retarget\WeekdayChange;

enum Kind: string
{
    /** The same number of days: the sessions re-map in order onto the new weekdays (SCH-040). */
    case Remap = 'remap';

    /** Fewer or more days: the user picks one of the choices. */
    case Choose = 'choose';
}
