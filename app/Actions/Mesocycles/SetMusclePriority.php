<?php

namespace App\Actions\Mesocycles;

use App\Domain\Defaults\TBL012TrainingDefaults\Column;
use App\Domain\Mesocycle\MesoBuilder;
use App\Domain\Model\MuscleGroup;
use App\Domain\Model\PhaseType;
use App\Domain\Model\Priority;
use App\Models\Mesocycle;
use App\Models\User;
use App\Support\Mesocycles\TrainSnapshot;
use DomainException;

/**
 * A muscle's priority: on a build (MESO-020), starting from the phase's preset the build showed (MESO-003), or on a
 * saved mesocycle, from its next session (MESO-032). Off (null) is for a saved mesocycle only (MESO-034): its slots are
 * then left out of the sessions to come ({@see TrainSnapshot::domain()}), and its feedback is not asked. The slots stay
 * stored, so a priority brings the muscle back at the rotation's sets.
 */
final class SetMusclePriority
{
    public function __invoke(User $user, string $mesocycleId, MuscleGroup $muscle, ?Priority $priority): Mesocycle
    {
        $snapshot = TrainSnapshot::load($user);
        $mesocycle = $snapshot->mesocycle($mesocycleId);
        if ($priority === null && ! $mesocycle->isSaved()) {
            throw new DomainException('A muscle can be set Off only once the mesocycle is running.');
        }

        $stored = $mesocycle->muscle_priorities ?? [];
        if ($stored === [] && ! $mesocycle->isSaved()) {
            $start = $mesocycle->start_date === null ? $snapshot->defaultStart() : TrainSnapshot::day($mesocycle->start_date);
            $column = Column::for($snapshot->phaseCovering($start)->type ?? PhaseType::Maintenance, $mesocycle->is_resensitization);
            $stored = array_map(fn (Priority $p) => $p->value, MesoBuilder::defaultPriorities($column));
        }

        if (array_key_exists($muscle->value, $stored) && $stored[$muscle->value] === $priority?->value) {
            return $mesocycle;
        }
        $stored[$muscle->value] = $priority?->value;
        $mesocycle->update(['muscle_priorities' => $stored]);

        return $mesocycle;
    }
}
