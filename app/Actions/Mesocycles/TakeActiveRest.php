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
 * phase covering its new start (MESO-003). When that phase has a different type from the copy's old one, the copy
 * takes its priorities again from the mesocycle taking the rest, as a copy made now would (MESO-065).
 */
final class TakeActiveRest
{
    /** @return list<string> the ids of the drafts it moved */
    public function __invoke(User $user, string $mesocycleId): array
    {
        $snapshot = TrainSnapshot::load($user, $mesocycleId);
        $mesocycle = $snapshot->mesocycle($mesocycleId);
        $next = $snapshot->nextMesocycle();

        if ($next === null || ! $next->mesocycle->is($mesocycle) || ! $next->recommendsActiveRest) {
            throw new DomainException('An active rest is recommended only after flat weeks, from the deload week on.');
        }

        $start = $snapshot->nextMesocycleStart($mesocycle, activeRest: true);
        $waiting = $snapshot->mesocycles->filter(fn (Mesocycle $m) => ! $m->isSaved()
            && $m->route === MesocycleRoute::CopyPrevious
            && $m->start_date !== null && TrainSnapshot::day($m->start_date) == $next->start)->values();

        $phase = $snapshot->phaseCovering($start);

        DB::transaction(function () use ($snapshot, $mesocycle, $start, $waiting, $phase) {
            $mesocycle->update(['active_rest' => true]);
            foreach ($waiting as $draft) {
                $draft->update([
                    'start_date' => $start->format('Y-m-d'),
                    'phase_id' => $phase?->id,
                    ...($phase?->type === $snapshot->phaseType($draft) ? [] : ['muscle_priorities' => $snapshot->copyPriorities($mesocycle, $start)]),
                ]);
            }
        });

        return $waiting->map(fn (Mesocycle $m) => (string) $m->id)->all();
    }
}
