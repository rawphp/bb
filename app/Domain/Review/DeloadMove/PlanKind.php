<?php

namespace App\Domain\Review\DeloadMove;

enum PlanKind
{
    /** "Deload now" is the only option: the diet break and the deload are one week. */
    case DeloadNow;
    /** "Make this your deload week too?" Yes moves the deload; No leaves training as planned. */
    case AskDeloadToo;
    /** Nothing to move: no mesocycle covers the week, the deload is already next week, or a shorter mesocycle would have no TBL-023 row. */
    case DietBreakOnly;
}
