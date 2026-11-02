<?php

namespace App\Actions\Mesocycles;

use App\Capabilities\Mesocycles\Data\MesocycleBuildDay;
use App\Capabilities\Mesocycles\Data\MesocycleBuildInput;
use App\Capabilities\Mesocycles\Data\MesocycleBuildSlot;
use App\Domain\Mesocycle\MesoBuilder;
use App\Domain\Mesocycle\MesocycleRules;
use App\Domain\Model\MuscleGroup;
use App\Enums\MesocycleRoute;
use App\Models\Exercise;
use App\Models\Mesocycle;
use App\Models\Template;
use App\Models\TemplateSlot;
use App\Models\User;
use App\Support\Mesocycles\TrainSnapshot;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Saves the create flow (MESO-003 to MESO-007, MESO-012 to MESO-014, MESO-020). A new id starts a draft and deletes the
 * user's other unfinished builds (MESO-007); after that the same id updates it. A field left out keeps its value.
 *
 * - `template_id` fills the days with the template's exercises and brings its name and priorities (MESO-012); a
 *   template without priorities leaves them to the phase (MESO-003).
 * - `days` replaces every day and slot (MESO-014). A slot keeps the load a copy carried while its exercise stays
 *   (MESO-061, MESO-062).
 * - The days are labelled by the split's style (MESO-005), when the days or the style change.
 * - `finish` saves it as a mesocycle, with the length, start and priorities the flow showed (MESO-003, MESO-004).
 */
final class BuildMesocycle
{
    public const NEEDS_NAME = 'Give the mesocycle a name first.';

    public const NEEDS_DAY = 'Add a day first.';

    /**
     * @param  ?Mesocycle  $existing  the caller's row with the input's id (CAP-019)
     * @return list<string> the ids of the builds it replaced
     */
    public function __invoke(User $user, ?Mesocycle $existing, MesocycleBuildInput $input): array
    {
        if ($existing !== null && $existing->isSaved()) {
            throw new DomainException('That mesocycle is already saved. Start a new build.');
        }

        $snapshot = TrainSnapshot::load($user);
        $template = $input->template_id === null ? null : Template::query()->with('slots')->findOrFail($input->template_id);
        $draft = $existing ?? (new Mesocycle)->forceFill([
            'id' => $input->mesocycle_id, 'user_id' => $user->id, 'is_draft' => true, 'is_resensitization' => false,
            'days' => [], 'muscle_priorities' => [],
        ]);
        $changes = [];

        $route = $input->route !== null ? MesocycleRoute::from($input->route) : ($template !== null ? MesocycleRoute::Template : null);
        if ($route !== null || $existing === null) {
            $changes['route'] = $route ?? MesocycleRoute::Scratch;
        }
        if ($input->name !== null || $template !== null) {
            // The template's name when none is given; one of the two is set here.
            $name = trim($input->name ?? $template->name);
            $changes['name'] = $name === '' ? null : $name;
        }
        if ($input->weeks !== null) {
            $range = MesocycleRules::lengthRange($snapshot->experience());
            if ($input->weeks < $range->lower || $input->weeks > $range->upper) {
                throw new DomainException("A mesocycle runs {$range->lower} to {$range->upper} weeks, the deload included.");
            }
            $changes['weeks'] = $input->weeks;
        }
        if ($input->start_date !== null) {
            $changes['start_date'] = $snapshot->snapped(TrainSnapshot::day($input->start_date))->format('Y-m-d');
        }
        if ($input->days_per_week !== null) {
            [$fewest, $most] = MesoBuilder::DAYS_PER_WEEK;
            if ($input->days_per_week < $fewest || $input->days_per_week > $most) {
                throw new DomainException("The Meso Builder plans {$fewest} to {$most} days a week.");
            }
            $changes['days_per_week'] = $input->days_per_week;
        }
        if ($template !== null) {
            $changes['muscle_priorities'] = $template->priorities ?? [];
        }

        $days = match (true) {
            $input->days !== null => $this->days($user, $input->days, $draft->dayRows()),
            $template !== null => self::templateDays($template),
            default => null,
        };
        if ($days !== null || $input->label_style !== null) {
            $changes['days'] = MesocycleRules::labelDays(
                $days ?? $draft->dayRows(),
                $input->label_style ?? self::style($draft->dayRows()),
                $snapshot->trainingWeekdaysInPlanOrder(),
            );
        }

        $draft->fill($changes);
        $start = $snapshot->buildStart($draft);
        $phase = $snapshot->phaseCovering($start);
        $draft->phase_id = $phase?->id;

        if ($input->finish) {
            match (true) {
                trim((string) $draft->name) === '' => throw new DomainException(self::NEEDS_NAME),
                $draft->dayRows() === [] => throw new DomainException(self::NEEDS_DAY),
                $phase === null => throw new DomainException(MesocycleRules::NO_PHASE),
                default => null,
            };
            $draft->fill([
                'is_draft' => false,
                'weeks' => $draft->weeks ?? MesocycleRules::defaultLength($snapshot->experience()),
                'start_date' => $start->format('Y-m-d'),
                // MESO-003: the priorities the flow showed, the phase's preset until the user changed one.
                'muscle_priorities' => $snapshot->buildPriorities($draft),
            ]);
        }

        return DB::transaction(function () use ($snapshot, $existing, $draft) {
            // MESO-007: one unfinished build at a time.
            $replaced = $existing !== null ? collect() : $snapshot->mesocycles
                ->filter(fn (Mesocycle $m) => ! $m->isSaved() && strcasecmp((string) $m->id, (string) $draft->id) !== 0)->values();
            $replaced->each->delete();
            $draft->save();

            return $replaced->map(fn (Mesocycle $m) => (string) $m->id)->all();
        });
    }

    /**
     * MESO-005: a build is a weekday split until its days float; one with no days yet starts as one.
     *
     * @param  list<array{weekday: ?int}>  $days
     * @return 'weekdays'|'floating'
     */
    private static function style(array $days): string
    {
        return $days === [] || MesocycleRules::isWeekdaySplit(array_column($days, 'weekday')) ? 'weekdays' : 'floating';
    }

    /**
     * MESO-012: the template's days with the exercises it preselects, each day and slot with a new id.
     *
     * @return list<array<string, mixed>>
     */
    private static function templateDays(Template $template): array
    {
        return array_map(fn (int $day) => [
            'id' => (string) Str::ulid(),
            'label' => null,
            'weekday' => null,
            'slots' => $template->slots
                ->filter(fn (TemplateSlot $slot) => $slot->day === $day)
                ->map(fn (TemplateSlot $slot) => [
                    'id' => (string) Str::ulid(),
                    'exercise_id' => $slot->exercise_id,
                    'muscle_group' => $slot->muscle_group->value,
                    'sets' => $slot->sets,
                    'carried' => null,
                ])->values()->all(),
        ], range(1, max(1, $template->days_per_week)));
    }

    /**
     * MESO-013, MESO-014: the days as the user edited them. Each exercise must be one the user can see, and train its
     * slot's muscle.
     *
     * @param  list<MesocycleBuildDay>  $days
     * @param  list<array{id: string, slots: list<array{id: string, exercise_id: ?int, carried: ?array{weight_kg: float, reps: int}}>}>  $stored
     * @return list<array<string, mixed>>
     */
    private function days(User $user, array $days, array $stored): array
    {
        $slots = array_merge(...array_map(fn (MesocycleBuildDay $day) => $day->slots, $days));
        $ids = array_values(array_unique(array_filter(array_map(fn (MesocycleBuildSlot $slot) => $slot->exercise_id, $slots), fn ($id) => $id !== null)));
        $exercises = Exercise::query()->visibleTo($user)->whereIn('id', $ids)->get()->keyBy(fn (Exercise $e) => (string) $e->id);

        $carried = [];
        foreach ($stored as $day) {
            foreach ($day['slots'] as $slot) {
                $carried[$slot['id']] = $slot;
            }
        }

        return array_map(fn (MesocycleBuildDay $day) => [
            'id' => $day->day_id,
            'label' => null,
            'weekday' => null,
            'slots' => array_map(function (MesocycleBuildSlot $slot) use ($exercises, $carried) {
                $exercise = $slot->exercise_id === null ? null
                    : ($exercises->get($slot->exercise_id) ?? throw (new ModelNotFoundException)->setModel(Exercise::class, [$slot->exercise_id]));
                $muscle = MuscleGroup::from($slot->muscle);
                if ($exercise !== null && $exercise->muscle_group !== $muscle) {
                    throw new DomainException("{$exercise->name} does not train {$muscle->title()}.");
                }
                $before = $carried[$slot->slot_id] ?? null;

                return [
                    'id' => $slot->slot_id,
                    'exercise_id' => $exercise?->id,
                    'muscle_group' => $muscle->value,
                    'sets' => $slot->sets,
                    'carried' => $before !== null && $exercise !== null && $before['exercise_id'] === $exercise->id ? $before['carried'] : null,
                ];
            }, $day->slots),
        ], $days);
    }
}
