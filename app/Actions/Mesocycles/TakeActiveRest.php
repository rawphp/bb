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
 * starts one plan week later. A copy of this mesocycle already made to start with the next one (MESO-060) moves with
 * it, into the phase covering its new start (MESO-003); copies of other mesocycles stay where they are. A copy whose
 * priorities are still the ones it was given takes them again from its source, as a copy made now would (MESO-065);
 * priorities the user kept or edited stay.
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
            && $m->route === MesocycleRoute::CopyPrevious && $m->copied_from_id === $mesocycle->id
            && $m->start_date !== null && TrainSnapshot::day($m->start_date) == $next->start)->values();

        $phase = $snapshot->phaseCovering($start);

        DB::transaction(function () use ($snapshot, $mesocycle, $next, $start, $waiting, $phase) {
            $mesocycle->update(['active_rest' => true]);
            foreach ($waiting as $draft) {
                // Priorities the user kept or edited are not what the copy wrote on its own, and stay.
                $untouched = $draft->muscle_priorities == $snapshot->copyPriorities($mesocycle, $next->start);
                $draft->update([
                    'start_date' => $start->format('Y-m-d'),
                    'phase_id' => $phase?->id,
                    ...($untouched ? ['muscle_priorities' => $snapshot->copyPriorities($mesocycle, $start)] : []),
                ]);
            }
        });

        return $waiting->map(fn (Mesocycle $m) => (string) $m->id)->all();
    }
}
