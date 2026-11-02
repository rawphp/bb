<?php

namespace App\Actions\Mesocycles;

use App\Domain\Defaults\TBL012TrainingDefaults\Column;
use App\Domain\Mesocycle\MesoBuilder;
use App\Domain\Model\Priority;
use App\Enums\MesocycleRoute;
use App\Models\Mesocycle;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Models\WorkoutSet;
use App\Support\Mesocycles\TrainSnapshot;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * MESO-060: copies a saved mesocycle into a draft (MESO-007) the create flow opens at its slots. Days and slots get
 * new ids and keep their labels, weekdays, muscles, sets and exercises. MESO-061, MESO-062: each slot carries the old
 * week-1 load of its exercise, so history follows the exercise; the rise (and the MESO-063 hold) is applied when the
 * targets are planned, from `carried`.
 *
 * MESO-003: the draft belongs to the phase covering its start. MESO-065: in a phase of another type it takes that
 * phase's priority preset, unless the old priorities are kept.
 */
final class CopyMesocycle
{
    /**
     * @param  ?Mesocycle  $existing  the caller's row with `$id`, for a retry of the same copy (CAP-019)
     * @param  ?string  $startDate  Y-m-d; null takes the next mesocycle's start, or next plan week
     * @return list<string> the ids of the drafts it replaced
     */
    public function __invoke(User $user, ?Mesocycle $existing, string $id, string $sourceId, ?string $startDate, bool $keepPriorities): array
    {
        if ($existing !== null && ! $existing->is_draft) {
            throw new DomainException('That mesocycle is already saved. Copy into a new one.');
        }

        $snapshot = TrainSnapshot::load($user, $sourceId);
        /** @var Mesocycle $source */
        $source = $snapshot->mesocycles->firstWhere('id', $sourceId);
        $start = $snapshot->snapped($startDate === null ? $snapshot->copyStart($source) : TrainSnapshot::day($startDate));
        $reset = $keepPriorities ? null : $snapshot->priorityReset($source, $start);

        $days = array_map(fn (array $day) => [
            'id' => self::newId(),
            'label' => $day['label'],
            'weekday' => $day['weekday'],
            'slots' => array_map(fn (array $slot) => [
                'id' => self::newId(),
                'exercise_id' => $slot['exercise_id'],
                'muscle_group' => $slot['muscle_group'],
                'sets' => $slot['sets'],
                'carried' => $this->weekOneLoad($snapshot, $source, $slot['exercise_id']),
            ], $day['slots']),
        ], $source->dayRows());

        return DB::transaction(function () use ($user, $existing, $id, $snapshot, $source, $start, $reset, $days) {
            $replaced = $snapshot->mesocycles->filter(fn (Mesocycle $m) => $m->is_draft && $m->id !== $id)->values();
            $replaced->each->delete();

            $draft = $existing ?? (new Mesocycle)->forceFill(['id' => $id, 'user_id' => $user->id]);
            $draft->fill([
                'phase_id' => $snapshot->phaseCovering($start)?->id,
                'name' => null,
                'weeks' => $source->weeks,
                'start_date' => $start->format('Y-m-d'),
                'ended_on' => null,
                'is_draft' => true,
                'is_resensitization' => false,
                'route' => MesocycleRoute::CopyPrevious,
                'active_rest' => false,
                'days' => $days,
                'muscle_priorities' => $reset === null
                    ? $source->muscle_priorities
                    : array_map(fn (Priority $p) => $p->value, MesoBuilder::defaultPriorities(Column::for($reset->type, false))),
                'earlier_weeks' => [],
                'later_weeks' => [],
            ])->save();

            return $replaced->map(fn (Mesocycle $m) => (string) $m->id)->all();
        });
    }

    /**
     * MESO-061, MESO-062: the first working set of the exercise logged in the source's week 1, as lifted.
     *
     * @return ?array{weight_kg: float, reps: int}
     */
    private function weekOneLoad(TrainSnapshot $snapshot, Mesocycle $source, ?int $exerciseId): ?array
    {
        if ($exerciseId === null) {
            return null;
        }
        $sessions = $snapshot->sessions
            ->filter(fn (WorkoutSession $s) => $s->mesocycle_id === $source->id && $s->week === 1)
            ->sortBy('day')
            ->pluck('id')
            ->all();
        foreach ($sessions as $session) {
            $set = $snapshot->sets->first(fn (WorkoutSet $set) => $set->workout_session_id === $session
                && $set->exercise_id === $exerciseId && $set->is_logged && ! $set->is_skipped
                && $set->actual_weight_kg !== null && $set->actual_reps !== null);
            if ($set !== null) {
                return ['weight_kg' => (float) $set->actual_weight_kg, 'reps' => (int) $set->actual_reps];
            }
        }

        return null;
    }

    private static function newId(): string
    {
        return (string) Str::ulid();
    }
}
