<?php

namespace App\Domain\Review;

enum ReviewStatusKind
{
    /** No finished week, or it has too few weigh-ins. */
    case Unavailable;

    /** REV-001, REV-002: the card shows. */
    case Available;

    /** The reviewed week's row has `reviewed_at` set. */
    case Done;
}
