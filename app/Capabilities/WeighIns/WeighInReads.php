<?php

namespace App\Capabilities\WeighIns;

use App\Capabilities\Support\ReadKey;
use App\Capabilities\Today\TodayReads;
use App\Models\Phase;
use App\Models\User;

/**
 * The reads a weigh-in, schedule or steps write changes (CAP-011, CAP-017): the Weigh-ins screen, and Today's
 * weigh-in and step rows (SCH-004, SCH-011) on each day it touched. A weigh-in also moves the trend each phase's
 * weights are shown from (PLAN-004).
 */
final class WeighInReads
{
    public const AFFECTS = ['weigh_ins_show', 'today_show'];

    public const WEIGH_IN_AFFECTS = [...self::AFFECTS, 'phase_show'];

    /**
     * @param  list<string>  $dates  Y-m-d, in the app's time zone (UTC), as today_show's dates are
     * @return list<string>
     */
    public static function keys(array $dates): array
    {
        return [ReadKey::for('weigh_ins_show'), ...TodayReads::keys($dates)];
    }

    /**
     * @param  list<string>  $dates  Y-m-d, in the app's time zone (UTC), as today_show's dates are
     * @return list<string>
     */
    public static function weighInKeys(User $user, array $dates): array
    {
        return [
            ...self::keys($dates),
            ...Phase::query()->where('user_id', $user->id)->orderBy('id')->pluck('id')
                ->map(fn ($id) => ReadKey::for('phase_show', ['phase_id' => (string) $id]))->all(),
        ];
    }
}
