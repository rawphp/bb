<?php

namespace App\Actions\Mesocycles;

use App\Models\Mesocycle;
use App\Models\User;
use App\Support\Mesocycles\TrainSnapshot;
use DomainException;

/**
 * MESO-064: takes the active rest train_show recommends: the deload week plus a week off, so the next mesocycle
 * starts one plan week later.
 */
final class TakeActiveRest
{
    public function __invoke(User $user, string $mesocycleId): Mesocycle
    {
        $snapshot = TrainSnapshot::load($user, $mesocycleId);
        /** @var Mesocycle $mesocycle */
        $mesocycle = $snapshot->mesocycles->firstWhere('id', $mesocycleId);
        $next = $snapshot->nextMesocycle();

        if ($next === null || ! $next->mesocycle->is($mesocycle) || ! $next->recommendsActiveRest) {
            throw new DomainException('An active rest is recommended only after flat weeks, from the deload week on.');
        }

        $mesocycle->update(['active_rest' => true]);

        return $mesocycle;
    }
}
