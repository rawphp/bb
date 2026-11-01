<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Database\Factories\RecipeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A recipe built from foods (RCP-001); a library recipe when it has no user (RCP-020).
 * A deleted recipe is only hidden: the meals it was planned in keep their copy (PlannedRecipe).
 */
#[Fillable(['slug', 'name', 'servings', 'meal_type', 'instructions'])]
class Recipe extends Model
{
    /** @use HasFactory<RecipeFactory> */
    use BelongsToUser, HasFactory, HasUlids, SoftDeletes;

    public const MEAL_TYPES = ['breakfast', 'lunch', 'dinner', 'snack'];

    /** @return HasMany<RecipeIngredient, $this> */
    public function ingredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class)->orderBy('position');
    }

    /**
     * The library, or one user's own recipes.
     *
     * @param  Builder<Recipe>  $query
     */
    #[Scope]
    protected function ownedBy(Builder $query, ?User $user): void
    {
        $query->where('user_id', $user?->id);
    }

    /**
     * RCP-020: one meal type, and every diet filter given (FOOD-027).
     *
     * @param  Builder<Recipe>  $query
     * @param  list<string>  $diets
     */
    #[Scope]
    protected function filtered(Builder $query, ?string $mealType, array $diets): void
    {
        $query->when($mealType, fn (Builder $query) => $query->where('meal_type', $mealType));
        foreach (array_intersect(Food::DIETS, $diets) as $diet) {
            $query->where($diet, true);
        }
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'servings' => 'float',
            'instructions' => 'array',
            ...array_fill_keys(Food::DIETS, 'boolean'),
        ];
    }
}
