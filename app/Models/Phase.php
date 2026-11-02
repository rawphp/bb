<?php

namespace App\Models;

use App\Domain\Model\PhaseType;
use App\Models\Concerns\BelongsToUser;
use Database\Factories\PhaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A phase on the plan timeline (PLAN-001). A soft-deleted phase keeps its mesocycles' phase_id, so a restore
 * re-links them; `Mesocycle::phase` hides a trashed phase meanwhile. A force delete nulls phase_id
 * through the foreign key.
 *
 * @property PhaseType $type
 */
#[Fillable(['type', 'start_date', 'end_date', 'target_rate_percent_per_week', 'event_date'])]
class Phase extends Model
{
    /** @use HasFactory<PhaseFactory> */
    use BelongsToUser, HasFactory, HasUuids, SoftDeletes;

    /** @return HasMany<Mesocycle, $this> */
    public function mesocycles(): HasMany
    {
        return $this->hasMany(Mesocycle::class);
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'type' => PhaseType::class,
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'event_date' => 'date:Y-m-d',
            'target_rate_percent_per_week' => 'float',
        ];
    }
}
