<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Database\Factories\ExerciseLoadStepFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The weight jump a user chose for an exercise, in kg (EX-012). */
#[Fillable(['user_id', 'exercise_id', 'step_kg'])]
class ExerciseLoadStep extends Model
{
    /** @use HasFactory<ExerciseLoadStepFactory> */
    use BelongsToUser, HasFactory;

    /** @return BelongsTo<Exercise, $this> */
    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['step_kg' => 'float'];
    }
}
