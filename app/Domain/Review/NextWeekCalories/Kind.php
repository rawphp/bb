<?php

namespace App\Domain\Review\NextWeekCalories;

enum Kind
{
    /** The week's own rules and no change: a planned diet break, deload or away week, or the phase's rate. */
    case Planned;
    /** Every day moves by the same amount so the week averages this many calories a day (REV-010). */
    case Average;
    /** REV-011: Drop fatigue, a diet break at maintenance. */
    case DietBreak;
}
