<?php

namespace App\Domain\Review;

/** What next week is, for the training step. */
enum NextWeekKind
{
    case Normal;
    case LastHardWeek;
    case Deload;

    public function title(): string
    {
        return match ($this) {
            self::Normal => 'Normal week',
            self::LastHardWeek => 'Last hard week',
            self::Deload => 'Deload week',
        };
    }
}
