<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Database\Factories\DayTargetMealFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The id of one meal in day_targets.meals. The meal's targets stay in that JSON; this row
 * exists so planned foods, check-ins and planning state can point at a meal with a real
 * foreign key. DayTarget keeps these rows in step with its meals.
 */
#[Fillable(['id', 'user_id', 'day_target_id', 'position'])]
class DayTargetMeal extends Model
{
    /** @use HasFactory<DayTargetMealFactory> */
    use BelongsToUser, HasFactory, HasUuids;

    public $timestamps = false;

    /**
     * The relations of every table whose foreign key points at a meal. A meal's plan goes with
     * it through these models, before the foreign keys would cascade, so a reversible write's undo
     * sees every row removed (CAP-013). A freeze test holds this list to the schema.
     */
    public const DEPENDENTS = ['plannedFoods', 'plannedRecipes', 'checkIn', 'planningState'];

    protected static function booted(): void
    {
        static::deleting(function (self $meal) {
            foreach (self::DEPENDENTS as $relation) {
                $meal->{$relation}()->get()->each->delete();
            }
        });
    }

    /**
     * The user's meals on a day that is not deleted: the meals a capability may name (CAP-020).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function liveOf(Builder $query, User $user): void
    {
        $query->where('user_id', $user->id)->whereHas('dayTarget');
    }

    /** @return BelongsTo<DayTarget, $this> */
    public function dayTarget(): BelongsTo
    {
        return $this->belongsTo(DayTarget::class);
    }

    /** @return HasMany<PlannedFood, $this> */
    public function plannedFoods(): HasMany
    {
        return $this->hasMany(PlannedFood::class, 'meal_id')->orderBy('position');
    }

    /** @return HasMany<PlannedRecipe, $this> */
    public function plannedRecipes(): HasMany
    {
        return $this->hasMany(PlannedRecipe::class, 'meal_id')->orderBy('position');
    }

    /** @return HasOne<MealCheckIn, $this> */
    public function checkIn(): HasOne
    {
        return $this->hasOne(MealCheckIn::class, 'meal_id');
    }

    /** @return HasOne<MealPlanningState, $this> */
    public function planningState(): HasOne
    {
        return $this->hasOne(MealPlanningState::class, 'meal_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['position' => 'integer'];
    }
}
