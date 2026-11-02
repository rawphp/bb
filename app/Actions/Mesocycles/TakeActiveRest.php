<?php

namespace App\Actions\Mesocycles;

use App\Enums\MesocycleRoute;
use App\Models\Mesocycle;
use App\Models\User;
use App\Support\Mesocycles\TrainSnapshot;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * MESO-064: takes the active rest train_show recommends: the deload week plus a week off, so the next mesocycle
 * starts one plan week later. A copy already made to start with the next mesocycle (MESO-060) moves with it, into the
 * phase covering its new start (MESO-003).
 */
final class TakeActiveRest
{
    /** @return list<string> the ids of the drafts it moved */
    public function __invoke(User $user, string $mesocycleId): array
    {
        $snapshot = TrainSnapshot::load($user, $mesocycleId);
        /** @var Mesocycle $mesocycle */
        $mesocycle = $snapshot->mesocycles->firstWhere('id', $mesocycleId);
        $next = $snapshot->nextMesocycle();

        if ($next === null || ! $next->mesocycle->is($mesocycle) || ! $next->recommendsActiveRest) {
            throw new DomainException('An active rest is recommended only after flat weeks, from the deload week on.');
        }

        $start = $snapshot->nextMesocycleStart($mesocycle, activeRest: true);
        $waiting = $snapshot->mesocycles->filter(fn (Mesocycle $m) => ! $m->isSaved()
            && $m->route === MesocycleRoute::CopyPrevious
            && $m->start_date !== null && TrainSnapshot::day($m->start_date) == $next->start)->values();

        DB::transaction(function () use ($snapshot, $mesocycle, $start, $waiting) {
            $mesocycle->update(['active_rest' => true]);
            $waiting->each->update([
                'start_date' => $start->format('Y-m-d'),
                'phase_id' => $snapshot->phaseCovering($start)?->id,
            ]);
        });

        return $waiting->map(fn (Mesocycle $m) => (string) $m->id)->all();
    }
}
