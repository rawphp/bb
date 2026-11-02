<?php

namespace App\Models;

use App\Domain\Mesocycle\MesocycleRules;
use App\Enums\MesocycleRoute;
use App\Models\Concerns\BelongsToUser;
use Database\Factories\MesocycleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A mesocycle, or a draft of one (MESO-007).
 *
 * The JSON columns hold:
 * - `days`: the weekly rotation, a list of `{id, label, weekday: ?int (ISO 1–7), slots}`, each slot
 *   `{id, exercise_id: ?int, muscle_group, sets, carried?: {weight_kg: float, reps: int}|null}`. `carried` is the
 *   old week-1 load a copy brings (MESO-061, MESO-062).
 * - `earlier_weeks`: SCH-039, the weeks before the training weekdays last changed, each a list of days as above.
 * - `later_weeks`: SCH-039, each later week as the list of day ids it runs.
 * - `muscle_priorities`: `{muscle: 'emphasize'|'grow'|'maintain'|null}`; null is Off (MESO-034) and a muscle left
 *   out reads as Maintain.
 *
 * Rows written before ids were stored have none; {@see dayRows()} gives each day and slot one from its position.
 * MESO-005: a day reads as its stored label, else its weekday's name, else "Day i"; every view shows that.
 *
 * @property list<array<string, mixed>>|null $days
 * @property list<list<array<string, mixed>>>|null $earlier_weeks
 * @property list<list<string>>|null $later_weeks
 * @property array<string, ?string>|null $muscle_priorities
 * @property ?MesocycleRoute $route MESO-007: the route a draft came from
 * @property ?string $copied_from_id MESO-060, MESO-065: the saved mesocycle a copy was made from
 * @property ?int $days_per_week MESO-020, MESO-022: the days per week a build lays out its days from
 */
#[Fillable([
    'phase_id', 'name', 'weeks', 'start_date', 'ended_on', 'is_draft', 'is_resensitization',
    'route', 'days_per_week', 'active_rest', 'copied_from_id', 'days', 'muscle_priorities', 'earlier_weeks', 'later_weeks',
])]
class Mesocycle extends Model
{
    /** @use HasFactory<MesocycleFactory> */
    use BelongsToUser, HasFactory, HasUuids, SoftDeletes;

    /** @return BelongsTo<Phase, $this> */
    public function phase(): BelongsTo
    {
        return $this->belongsTo(Phase::class);
    }

    /** @return BelongsTo<self, $this> MESO-060: the saved mesocycle this copy was made from */
    public function copiedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'copied_from_id');
    }

    /** @return HasMany<WorkoutSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(WorkoutSession::class);
    }

    /**
     * The user's saved mesocycles: not drafts, and with a start and a length.
     *
     * @return Builder<self>
     */
    public static function savedBy(User $user): Builder
    {
        return self::query()->where('user_id', $user->id)->where('is_draft', false)
            ->whereNotNull('start_date')->whereNotNull('weeks');
    }

    /**
     * MESO-007: the user's unfinished builds: drafts, and rows saved without dates.
     *
     * @return Builder<self>
     */
    public static function draftsOf(User $user): Builder
    {
        return self::query()->where('user_id', $user->id)
            ->where(fn (Builder $q) => $q->where('is_draft', true)->orWhereNull('start_date')->orWhereNull('weeks'));
    }

    /** Not a draft, and dated: what MESO-001 lists and mesocycle_show shows as saved. */
    public function isSaved(): bool
    {
        return ! $this->is_draft && $this->start_date !== null && $this->weeks !== null;
    }

    /**
     * The rotation, every day and slot with an id.
     *
     * @return list<array{id: string, label: string, weekday: ?int, slots: list<array{id: string, exercise_id: ?int, muscle_group: string, sets: int, carried: ?array{weight_kg: float, reps: int}}>}>
     */
    public function dayRows(): array
    {
        return self::rows($this->days ?? [], 'day-');
    }

    /**
     * SCH-039: the weeks as they ran before the weekdays changed.
     *
     * @return list<list<array{id: string, label: string, weekday: ?int, slots: list<array{id: string, exercise_id: ?int, muscle_group: string, sets: int, carried: ?array{weight_kg: float, reps: int}}>}>>
     */
    public function earlierWeekRows(): array
    {
        return array_map(
            fn (int $week): array => self::rows($this->earlier_weeks[$week] ?? [], "week-{$week}-day-"),
            array_keys($this->earlier_weeks ?? []),
        );
    }

    /** @return list<list<string>> */
    public function laterWeekIds(): array
    {
        return $this->later_weeks ?? [];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'weeks' => 'integer',
            'start_date' => 'date:Y-m-d',
            'ended_on' => 'date:Y-m-d',
            'is_draft' => 'boolean',
            'is_resensitization' => 'boolean',
            'route' => MesocycleRoute::class,
            'days_per_week' => 'integer',
            'active_rest' => 'boolean',
            'days' => 'array',
            'muscle_priorities' => 'array',
            'earlier_weeks' => 'array',
            'later_weeks' => 'array',
        ];
    }

    /**
     * @param  array<mixed>  $days
     * @return list<array{id: string, label: string, weekday: ?int, slots: list<array{id: string, exercise_id: ?int, muscle_group: string, sets: int, carried: ?array{weight_kg: float, reps: int}}>}>
     */
    private static function rows(array $days, string $prefix): array
    {
        $rows = [];
        foreach (array_values($days) as $index => $day) {
            $id = (string) ($day['id'] ?? $prefix.($index + 1));
            $slots = [];
            foreach (array_values($day['slots'] ?? []) as $position => $slot) {
                $carried = $slot['carried'] ?? null;
                $slots[] = [
                    'id' => (string) ($slot['id'] ?? "{$id}-slot-".($position + 1)),
                    'exercise_id' => isset($slot['exercise_id']) ? (int) $slot['exercise_id'] : null,
                    'muscle_group' => (string) $slot['muscle_group'],
                    'sets' => (int) $slot['sets'],
                    'carried' => is_array($carried) ? ['weight_kg' => (float) $carried['weight_kg'], 'reps' => (int) $carried['reps']] : null,
                ];
            }
            $rows[] = [
                'id' => $id,
                'label' => self::label($day, $index),
                'weekday' => isset($day['weekday']) ? (int) $day['weekday'] : null,
                'slots' => $slots,
            ];
        }

        return $rows;
    }

    /**
     * MESO-005: the stored label, else the weekday's name, else "Day i".
     *
     * @param  array<mixed>  $day
     */
    private static function label(array $day, int $index): string
    {
        $label = trim((string) ($day['label'] ?? ''));

        return match (true) {
            $label !== '' => $label,
            isset($day['weekday']) => MesocycleRules::weekdayName((int) $day['weekday']),
            default => 'Day '.($index + 1),
        };
    }
}
