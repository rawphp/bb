<?php

namespace App\Capabilities\Settings;

use App\Capabilities\Support\ReadKey;
use App\Capabilities\Today\TodayReads;
use Carbon\CarbonImmutable;

/**
 * The reads a settings write changes (CAP-011): settings_show, and after a rebuild of the targets (SET-004) each
 * rebuilt day's Today and the week strip of its plan week, which shows every day's totals.
 */
final class SettingsReads
{
    /** What a write that can rebuild the targets names in `affects`. */
    public const REBUILDS = ['settings_show', 'today_show', 'week_show'];

    /**
     * @param  list<string>  $rebuilt  the days the rebuild wrote, Y-m-d: the rest of one plan week, in order
     * @return list<string>
     */
    public static function keys(array $rebuilt): array
    {
        if ($rebuilt === []) {
            return [ReadKey::for('settings_show')];
        }

        // The rebuilt days run to the end of their plan week, so the week is the seven days that end on the last.
        $last = CarbonImmutable::parse($rebuilt[count($rebuilt) - 1]);
        $week = array_map(fn (int $back) => $last->subDays($back)->format('Y-m-d'), range(6, 0));

        return [ReadKey::for('settings_show'), ...TodayReads::keys($rebuilt), ...TodayReads::weekKeys($week)];
    }
}
