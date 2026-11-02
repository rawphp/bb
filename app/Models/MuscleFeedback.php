<?php

namespace App\Models;

use App\Domain\Model\MuscleGroup;
use App\Models\Concerns\BelongsToUser;
use Database\Factories\MuscleFeedbackFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What the user said of a muscle after a session: soreness, pump and volume (FB-003 to FB-005).
 *
 * @property MuscleGroup $muscle_group
 */
#[Fillable(['workout_session_id', 'muscle_group', 'soreness', 'pump', 'volume'])]
class MuscleFeedback extends Model
{
    /** @use HasFactory<MuscleFeedbackFactory> */
    use BelongsToUser, HasFactory, HasUuids, SoftDeletes;

    protected $table = 'muscle_feedback';

    /** @return BelongsTo<WorkoutSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(WorkoutSession::class, 'workout_session_id');
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return ['muscle_group' => MuscleGroup::class];
    }
}
