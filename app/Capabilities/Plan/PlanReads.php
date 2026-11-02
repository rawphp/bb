<?php

namespace App\Capabilities\Plan;

use App\Capabilities\Support\ReadKey;
use App\Capabilities\Today\TodayReads;
use App\Models\Mesocycle;
use App\Models\Phase;
use App\Models\User;

/**
 * The reads a plan write changes (CAP-011, CAP-017): the Plan screen, each phase it changed, the Train tab and the
 * mesocycles, whose weeks follow the phase covering them (PLAN-002), and today's screen and week strip, whose deload
 * labels and calories do (PLAN-022).
 */
final class PlanReads
{
    /** The reads every plan write names in `affects`. */
    public const AFFECTS = ['plan_show', 'phase_show', 'train_show', 'mesocycle_show', 'today_show', 'week_show'];

    /** @return list<string> */
    public static function keys(User $user, string ...$phaseIds): array
    {
        return [
            ReadKey::for('plan_show'),
            ...array_map(fn (string $id) => ReadKey::for('phase_show', ['phase_id' => $id]), array_values(array_unique($phaseIds))),
            ReadKey::for('train_show'),
            ...Mesocycle::savedBy($user)->pluck('id')->map(fn ($id) => ReadKey::for('mesocycle_show', ['mesocycle_id' => (string) $id]))->all(),
            ReadKey::for('today_show'),
            ReadKey::for('week_show'),
        ];
    }

    /**
     * The same, naming every phase the user has: a write that changes what each phase shows, such as its target
     * weight (PLAN-025) or whether it has a rate at all (PLAN-007).
     *
     * @return list<string>
     */
    public static function everyPhaseKeys(User $user): array
    {
        return self::keys($user, ...Phase::query()->where('user_id', $user->id)->pluck('id')->map(fn ($id) => (string) $id)->all());
    }

    /**
     * PLAN-023, PLAN-025: a deload's diet break changes that week's days and every phase's target weight and length
     * cap, which count diet-break weeks.
     *
     * @param  list<string>  $days  the deload week's days, Y-m-d
     * @return list<string>
     */
    public static function dietBreakKeys(User $user, array $days): array
    {
        return array_values(array_unique([
            ...self::everyPhaseKeys($user),
            ...TodayReads::keys($days),
            ...TodayReads::weekKeys($days),
        ]));
    }
}
