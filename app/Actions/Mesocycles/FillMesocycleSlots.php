<?php

namespace App\Actions\Mesocycles;

use App\Domain\Mesocycle\Exercise as BuilderExercise;
use App\Domain\Mesocycle\MesoBuilder;
use App\Domain\Mesocycle\StimulusToFatigue;
use App\Domain\Model\MuscleGroup;
use App\Models\Exercise;
use App\Models\Mesocycle;
use App\Models\User;
use App\Support\Mesocycles\TrainSnapshot;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * "Pick for me" (MESO-013, MESO-016): fills every slot of a build that has no exercise with the best stimulus-to-fatigue
 * exercise the user can see for its muscle, compounds first. A slot with an exercise keeps it; a muscle with none in
 * the library stays empty.
 */
final class FillMesocycleSlots
{
    /** @return int how many slots it filled */
    public function __invoke(User $user, string $mesocycleId): int
    {
        $snapshot = TrainSnapshot::load($user);
        $draft = $snapshot->mesocycle($mesocycleId);
        if ($draft->isSaved()) {
            throw (new ModelNotFoundException)->setModel(Mesocycle::class, [$mesocycleId]);
        }

        $library = $snapshot->exercises()->values();
        $candidates = $library->map(fn (Exercise $e) => new BuilderExercise(
            $e->name, $e->muscle_group, StimulusToFatigue::from($e->sfr_rating->value), $e->is_compound,
        ))->all();

        // The stored days, so only the exercises change and the labels stay as they were (MESO-005).
        $days = $draft->days ?? [];
        $filled = 0;
        foreach ($draft->dayRows() as $d => $day) {
            foreach ($day['slots'] as $s => $slot) {
                if ($slot['exercise_id'] !== null) {
                    continue;
                }
                $pick = MesoBuilder::pickForMe(MuscleGroup::from($slot['muscle_group']), $candidates);
                $index = $pick === null ? false : array_search($pick, $candidates, true);
                if ($index !== false) {
                    $days[$d]['slots'] = array_values($days[$d]['slots']);
                    $days[$d]['slots'][$s]['exercise_id'] = $library[$index]->id;
                    $filled++;
                }
            }
        }

        if ($filled > 0) {
            $draft->update(['days' => $days]);
        }

        return $filled;
    }
}
