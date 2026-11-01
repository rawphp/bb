<?php

namespace App\Domain\Model;

/** What the day's consumed totals count (SET-003, DAY-011). */
enum ConsumedTotals: string
{
    case Planned = 'planned';
    case CheckedIn = 'checked_in';

    public function title(): string
    {
        return match ($this) {
            self::Planned => 'Include planned meals',
            self::CheckedIn => 'Checked-in meals only',
        };
    }
}
