<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Database\Factories\PlannedRecipeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Servings of a recipe planned in a meal (RCP-001, RCP-002). `recipe` is this meal's copy of
 * the recipe: it follows the recipe until the meal is checked in, then stays as it was (RCP-021). Keyed by a ULID, so
 * the outbox can create one offline (CAP-019).
 *
 * @property string $id
 */
#[Fillable(['id', 'user_id', 'meal_id', 'recipe_id', 'position', 'servings', 'is_locked', 'recipe'])]
class PlannedRecipe extends Model
{
    /** @use HasFactory<PlannedRecipeFactory> */
    use BelongsToUser, HasFactory, HasUlids;

    /** @return BelongsTo<DayTargetMeal, $this> */
    public function meal(): BelongsTo
    {
        return $this->belongsTo(DayTargetMeal::class, 'meal_id');
    }

    /** @return BelongsTo<Recipe, $this> */
    public function sourceRecipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class, 'recipe_id')->withTrashed();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'servings' => 'float',
            'is_locked' => 'boolean',
            'recipe' => 'array',
        ];
    }
}
