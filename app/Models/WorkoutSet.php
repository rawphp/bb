<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Database\Factories\WorkoutSetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One set of an exercise in a session: what was targeted and what was lifted (MESO-062). */
#[Fillable([
    'workout_session_id', 'workout_exercise_id', 'exercise_id', 'position', 'type', 'variant',
    'target_weight_kg', 'target_reps', 'actual_weight_kg', 'actual_reps', 'is_logged', 'is_skipped',
])]
class WorkoutSet extends Model
{
    /** @use HasFactory<WorkoutSetFactory> */
    use BelongsToUser, HasFactory, HasUlids, SoftDeletes;

    /** @return BelongsTo<WorkoutSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(WorkoutSession::class, 'workout_session_id');
    }

    /** @return BelongsTo<WorkoutExercise, $this> the exercise of the session the set belongs to; null on rows written before there was one */
    public function workoutExercise(): BelongsTo
    {
        return $this->belongsTo(WorkoutExercise::class);
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
            'target_weight_kg' => 'float',
            'target_reps' => 'integer',
            'actual_weight_kg' => 'float',
            'actual_reps' => 'integer',
            'is_logged' => 'boolean',
            'is_skipped' => 'boolean',
        ];
    }
}
