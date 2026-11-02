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

    /** @return HasMany<PlannedRecipe, $this> */
    public function plannedRecipes(): HasMany
    {
        return $this->hasMany(PlannedRecipe::class);
    }

    /**
     * RCP-021: the recipe as a meal it is planned in keeps it (PlannedRecipe::$recipe). A meal not checked in takes
     * it again after every edit; a checked-in meal keeps the one it had. Needs `ingredients` loaded.
     *
     * @return array{id: string, name: string, servings: float, meal_type: string, instructions: list<string>, ingredients: list<array{food_id: string, grams: float, is_cooked: bool}>}
     */
    public function plannedCopy(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'servings' => $this->servings,
            'meal_type' => $this->meal_type,
            'instructions' => $this->instructions ?? [],
            'ingredients' => $this->ingredients->map(fn (RecipeIngredient $ingredient): array => [
                'food_id' => $ingredient->food_id,
                'grams' => $ingredient->grams,
                'is_cooked' => $ingredient->is_cooked,
            ])->values()->all(),
        ];
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
     * RCP-001, RCP-020: what a user may open: the library and their own recipes.
     *
     * @param  Builder<Recipe>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('user_id')->orWhere('user_id', $user->id));
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
