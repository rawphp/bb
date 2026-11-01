<?php

namespace App\Models;

use App\Enums\PhaseType;
use App\Models\Concerns\BelongsToUser;
use Database\Factories\PhaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A phase on the plan timeline (PLAN-001). */
#[Fillable(['type', 'start_date', 'end_date', 'target_rate_percent_per_week', 'event_date'])]
class Phase extends Model
{
    /** @use HasFactory<PhaseFactory> */
    use BelongsToUser, HasFactory, HasUuids, SoftDeletes;

    /** A deleted phase leaves its mesocycles, as the foreign key's nullOnDelete would. */
    protected static function booted(): void
    {
        static::deleted(fn (Phase $phase) => Mesocycle::where('phase_id', $phase->id)->update(['phase_id' => null]));
    }

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
