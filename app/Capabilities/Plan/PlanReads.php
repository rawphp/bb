<?php

namespace App\Capabilities\Plan;

use App\Capabilities\Support\ReadKey;
use App\Models\Mesocycle;
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
}
