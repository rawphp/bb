<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Database\Factories\MesocycleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A mesocycle, or a draft of one (MESO-007). */
#[Fillable([
    'phase_id', 'name', 'weeks', 'start_date', 'ended_on', 'is_draft', 'is_resensitization',
    'days', 'muscle_priorities', 'earlier_weeks', 'later_weeks',
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

    /** @return HasMany<WorkoutSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(WorkoutSession::class);
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
            'days' => 'array',
            'muscle_priorities' => 'array',
            'earlier_weeks' => 'array',
            'later_weeks' => 'array',
        ];
    }
}
