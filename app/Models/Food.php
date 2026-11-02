<?php

namespace App\Models;

use App\Domain\Foods\FoodKind;
use App\Domain\Foods\FoodSource;
use App\Models\Concerns\BelongsToUser;
use Database\Factories\FoodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A food, per 100 g (raw for foods that change weight when cooked). Imported foods have no user;
 * a custom food (FOOD-060) belongs to the user who made it, with source and kind `custom`.
 * The owner, source and curation columns are not fillable: whoever creates the row sets them by name.
 */
#[Fillable([
    'name', 'brand', 'barcode', 'kcal', 'protein', 'fat', 'carbs', 'fiber', 'cooked_ratio', 'custom_serving',
    ...self::DIETS,
])]
class Food extends Model
{
    /** @use HasFactory<FoodFactory> */
    use BelongsToUser, HasFactory, HasUlids, SoftDeletes;

    protected $table = 'foods';

    /** The diet flags, in API order (FOOD-027). */
    public const DIETS = ['dairy_free', 'gluten_free', 'grain_free', 'low_fodmap', 'vegetarian', 'paleo', 'vegan'];

    /** FOOD-060: the units a custom food's serving can be measured in. */
    public const SERVING_UNITS = ['g', 'cup', 'tbsp', 'tsp', 'piece'];

    /** The credit the food's dataset (its source) asks for, shown in the food detail; null for the curated and custom sources. */
    public function attribution(): ?string
    {
        return FoodSource::tryFrom((string) $this->source)?->attribution();
    }

    /** @return HasMany<FoodServing, $this> */
    public function servings(): HasMany
    {
        return $this->hasMany(FoodServing::class);
    }

    /**
     * The shared database plus the user's own custom foods.
     *
     * @param  Builder<Food>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('user_id')->orWhere('user_id', $user->id));
    }

    /**
     * FOOD-061: the user's own custom foods, the only foods the custom food capabilities read or change.
     *
     * @param  Builder<Food>  $query
     */
    #[Scope]
    protected function customOf(Builder $query, User $user): void
    {
        $query->where('user_id', $user->id)->where('kind', FoodKind::Custom->value);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'custom_serving' => 'array',
            'curated' => 'boolean',
            'kcal' => 'float',
            'protein' => 'float',
            'fat' => 'float',
            'carbs' => 'float',
            'fiber' => 'float',
            'cooked_ratio' => 'float',
            ...array_fill_keys(self::DIETS, 'boolean'),
        ];
    }
}
