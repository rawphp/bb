<?php

namespace App\Domain\Retarget\MissedDayOption;

enum Kind: string
{
    /** Offered only when today is a rest day. Only that session moves. */
    case DoItToday = 'do_it_today';

    /** The next rest day left in the plan week. */
    case NextRestDay = 'next_rest_day';

    /** The session is marked skipped; the past day's targets and adherence stay as they were. */
    case Skip = 'skip';
}
