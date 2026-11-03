<?php

namespace App\Actions\Mesocycles;

use App\Domain\Mesocycle\MesoBuilder;
use App\Domain\Mesocycle\MesocycleRules;
use App\Domain\Model\ExerciseSlot;
use App\Domain\Model\Session;
use App\Enums\MesocycleRoute;
use App\Models\Mesocycle;
use App\Models\User;
use App\Support\Mesocycles\TrainSnapshot;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Continue" on the builder or scratch step: lays out a build's days from its days per week (MESO-020). The Meso
 * Builder puts each muscle on its days with a slot per day, each at 2 sets and with no exercise yet (MESO-022,
 * MESO-023); build from scratch makes empty days (MESO-002). Either replaces the build's days, which are labelled as
 * a weekday split when the days match the training days (MESO-005).
 */
final class GenerateMesocycleStructure
{
    public function __invoke(User $user, string $mesocycleId): Mesocycle
    {
        return DB::transaction(function () use ($user, $mesocycleId) {
            // Two writes take turns, and the one that waited reads with locks so it sees what the other saved.
            Mesocycle::query()->where('user_id', $user->id)->whereKey($mesocycleId)->lockForUpdate()->first();
            $snapshot = TrainSnapshot::load($user, locked: true);
            $draft = $snapshot->mesocycle($mesocycleId);
            if ($draft->isSaved()) {
                throw (new ModelNotFoundException)->setModel(Mesocycle::class, [$mesocycleId]);
            }
            $route = $draft->route ?? MesocycleRoute::Scratch;
            if ($route === MesocycleRoute::Template || $route === MesocycleRoute::CopyPrevious) {
                throw new DomainException('This build already has its days.');
            }

            $training = $snapshot->trainingWeekdaysInPlanOrder();
            $daysPerWeek = $draft->days_per_week ?? MesoBuilder::daysPerWeek($draft->dayRows(), $training);
            $newId = fn (): string => (string) Str::ulid();

            if ($route === MesocycleRoute::Builder) {
                $column = $snapshot->buildColumn($draft);
                $days = array_map(fn (Session $session) => [
                    'id' => $session->id,
                    'slots' => array_map(fn (ExerciseSlot $slot) => [
                        'id' => $slot->id, 'exercise_id' => null, 'muscle_group' => $slot->muscle->value, 'sets' => MesoBuilder::DEFAULT_SETS,
                    ], $session->slots),
                ], MesoBuilder::sessions($daysPerWeek, MesoBuilder::priorities($draft->muscle_priorities ?? [], $column), $column, $newId));
            } else {
                $days = array_map(fn () => ['id' => $newId(), 'slots' => []], range(1, $daysPerWeek));
            }

            $draft->update([
                'days' => MesocycleRules::labelDays($days, 'weekdays', $training),
                'days_per_week' => $daysPerWeek,
            ]);

            return $draft;
        });
    }
}
