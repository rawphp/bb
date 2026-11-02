<?php

namespace App\Models;

use App\Domain\Model\Equipment;
use App\Domain\Model\ExerciseLoadType;
use App\Domain\Model\MuscleGroup;
use App\Enums\SfrRating;
use App\Models\Concerns\BelongsToUser;
use Database\Factories\ExerciseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A library exercise, or a user's custom one when it has a user.
 *
 * @property MuscleGroup $muscle_group
 * @property Equipment $equipment
 * @property ExerciseLoadType $load_type
 * @property SfrRating $sfr_rating
 * @property bool $is_compound
 */
#[Fillable([
    'name', 'muscle_group', 'equipment', 'load_type', 'sfr_rating',
    'rep_range_min', 'rep_range_max', 'is_compound', 'video_url',
])]
class Exercise extends Model
{
    /** @use HasFactory<ExerciseFactory> */
    use BelongsToUser, HasFactory;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'muscle_group' => MuscleGroup::class,
            'equipment' => Equipment::class,
            'load_type' => ExerciseLoadType::class,
            'sfr_rating' => SfrRating::class,
            'rep_range_min' => 'integer',
            'rep_range_max' => 'integer',
            'is_compound' => 'boolean',
        ];
    }

    /** @return HasMany<WorkoutSet, $this> */
    public function sets(): HasMany
    {
        return $this->hasMany(WorkoutSet::class);
    }

    /**
     * Library exercises plus the user's own custom ones.
     *
     * @param  Builder<Exercise>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('user_id')->orWhere('user_id', $user->id));
    }
}
