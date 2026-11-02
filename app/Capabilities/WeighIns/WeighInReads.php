<?php

namespace App\Capabilities\WeighIns;

use App\Capabilities\Support\ReadKey;
use App\Capabilities\Today\TodayReads;

/**
 * The reads a weigh-in, schedule or steps write changes (CAP-011, CAP-017): the Weigh-ins screen, and Today's
 * weigh-in and step rows (SCH-004, SCH-011) on each day it touched.
 */
final class WeighInReads
{
    public const AFFECTS = ['weigh_ins_show', 'today_show'];

    /**
     * @param  list<string>  $dates  Y-m-d
     * @return list<string>
     */
    public static function keys(array $dates): array
    {
        return [ReadKey::for('weigh_ins_show'), ...TodayReads::keys($dates)];
    }
}
