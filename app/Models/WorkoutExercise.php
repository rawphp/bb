<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Database\Factories\WorkoutExerciseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One exercise of a started session (WO-002), in session order, with its sets and this session's note (WO-022).
 * Its id is the one workout_show sent before the start (OFF-021).
 */
#[Fillable(['workout_session_id', 'exercise_id', 'position', 'note'])]
class WorkoutExercise extends Model
{
    /** @use HasFactory<WorkoutExerciseFactory> */
    use BelongsToUser, HasFactory, HasUlids, SoftDeletes;

    /** @return BelongsTo<WorkoutSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(WorkoutSession::class, 'workout_session_id');
    }

    /** @return HasMany<WorkoutSet, $this> */
    public function sets(): HasMany
    {
        return $this->hasMany(WorkoutSet::class)->orderBy('position');
    }

    /** @return BelongsTo<Exercise, $this> */
    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'exercise_id' => 'integer',
            'position' => 'integer',
        ];
    }
}
