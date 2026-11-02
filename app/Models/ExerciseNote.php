<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Database\Factories\ExerciseNoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The note a user pinned on an exercise, shown whenever it comes up (EX-010). */
#[Fillable(['user_id', 'exercise_id', 'note'])]
class ExerciseNote extends Model
{
    /** @use HasFactory<ExerciseNoteFactory> */
    use BelongsToUser, HasFactory;

    /** @return BelongsTo<Exercise, $this> */
    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }
}
