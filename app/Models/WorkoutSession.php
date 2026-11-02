<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Database\Factories\WorkoutSessionFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One training session of a mesocycle, planned and as it was done. */
#[Fillable([
    'mesocycle_id', 'week', 'day', 'planned_start_at', 'planned_end_at',
    'actual_start_at', 'actual_end_at', 'is_skipped', 'notes',
])]
class WorkoutSession extends Model
{
    /** @use HasFactory<WorkoutSessionFactory> */
    use BelongsToUser, HasFactory, HasUuids, SoftDeletes;

    /** @return BelongsTo<Mesocycle, $this> */
    public function mesocycle(): BelongsTo
    {
        return $this->belongsTo(Mesocycle::class);
    }

    /** @return HasMany<WorkoutSet, $this> */
    public function sets(): HasMany
    {
        return $this->hasMany(WorkoutSet::class);
    }

    /** @return HasMany<MuscleFeedback, $this> */
    public function muscleFeedback(): HasMany
    {
        return $this->hasMany(MuscleFeedback::class);
    }

    /** @return HasMany<ExerciseFeedback, $this> */
    public function exerciseFeedback(): HasMany
    {
        return $this->hasMany(ExerciseFeedback::class);
    }

    /**
     * A timestamp with an offset is shifted to the app timezone before it is stored,
     * so a time sent as +10:00 lands as the same instant.
     *
     * @param  mixed  $value
     */
    public function fromDateTime($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $time = $value instanceof DateTimeInterface ? $this->asDateTime($value) : $this->asDateTime((string) $value);

        return parent::fromDateTime($time->setTimezone(config('app.timezone')));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'week' => 'integer',
            'day' => 'integer',
            'planned_start_at' => 'datetime',
            'planned_end_at' => 'datetime',
            'actual_start_at' => 'datetime',
            'actual_end_at' => 'datetime',
            'is_skipped' => 'boolean',
        ];
    }
}
