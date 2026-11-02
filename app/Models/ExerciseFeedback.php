<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Database\Factories\ExerciseFeedbackFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** What the user said of an exercise's joint pain after a session (FB-002). */
#[Fillable(['workout_session_id', 'exercise_id', 'joint_pain'])]
class ExerciseFeedback extends Model
{
    /** @use HasFactory<ExerciseFeedbackFactory> */
    use BelongsToUser, HasFactory, HasUuids, SoftDeletes;

    protected $table = 'exercise_feedback';

    /** @return BelongsTo<WorkoutSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(WorkoutSession::class, 'workout_session_id');
    }

    /** @return BelongsTo<Exercise, $this> */
    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['exercise_id' => 'integer'];
    }
}
