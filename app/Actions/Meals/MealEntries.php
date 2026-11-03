<?php

namespace App\Actions\Meals;

use App\Actions\Foods\MealFitFoods;
use App\Domain\MealFit\MealFood;
use App\Domain\MealFit\Weighing;
use App\Domain\Model\MealTarget;
use App\Domain\Recipe\RecipeServing;
use App\Models\DayTargetMeal;
use App\Models\Food;
use App\Models\PlannedFood;
use App\Models\PlannedRecipe;
use App\Models\Recipe;
use App\Models\User;
use App\Support\Today\TodaySnapshot;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * One of the user's meals with its planned foods and recipe servings, as the meal rules see them (MEAL-002 to
 * MEAL-008): each entry is a MealFood keyed by its row's ULID, in planned order, as the meal screen shows them. Load it
 * inside a transaction: the meal is locked until that ends. A meal or an entry that is not the user's is not found.
 */
final class MealEntries
{
    /**
     * @param  Collection<string, PlannedFood>  $foods  by lowercase id
     * @param  Collection<string, PlannedRecipe>  $recipes  by lowercase id
     */
    private function __construct(
        public readonly DayTargetMeal $meal,
        private Collection $foods,
        private Collection $recipes,
    ) {}

    /** @throws ModelNotFoundException when the meal is not one of the user's live meals */
    public static function load(User $user, string $mealId): self
    {
        $meal = DayTargetMeal::query()->liveOf($user)->lockForUpdate()
            ->with(['dayTarget', 'plannedFoods.food.servings', 'plannedRecipes.sourceRecipe.ingredients.food'])
            ->findOrFail($mealId);

        return new self(
            $meal,
            $meal->plannedFoods->keyBy(fn (PlannedFood $f) => strtolower($f->id)),
            $meal->plannedRecipes->keyBy(fn (PlannedRecipe $r) => strtolower($r->id)),
        );
    }

    /** The meal's targets, kept in its day's meals (NUT-030). */
    public function target(): MealTarget
    {
        foreach ($this->meal->dayTarget->meals as $target) {
            if (strcasecmp((string) ($target['id'] ?? ''), (string) $this->meal->id) === 0) {
                return new MealTarget(
                    kcal: (float) ($target['kcal'] ?? 0),
                    proteinG: (float) ($target['protein_g'] ?? 0),
                    fatG: (float) ($target['fat_g'] ?? 0),
                    carbsG: (float) ($target['carbs_g'] ?? 0),
                );
            }
        }

        throw (new ModelNotFoundException)->setModel(DayTargetMeal::class, [$this->meal->id]);
    }

    /**
     * The foods and recipe servings in their shared planned order.
     *
     * @return list<MealFood>
     */
    public function entries(): array
    {
        $entries = [];
        foreach ($this->foods as $planned) {
            $entries[] = [$planned->position, self::food($planned)];
        }
        foreach ($this->recipes as $planned) {
            $entries[] = [$planned->position, self::recipe($planned)];
        }
        usort($entries, fn (array $a, array $b) => $a[0] <=> $b[0]);

        return array_map(fn (array $entry) => $entry[1], $entries);
    }

    /** @throws ModelNotFoundException when the meal holds no entry with this id */
    public function entry(string $id): MealFood
    {
        $key = strtolower($id);
        $planned = $this->foods[$key] ?? $this->recipes[$key] ?? throw (new ModelNotFoundException)->setModel(PlannedFood::class, [$id]);

        return $planned instanceof PlannedFood ? self::food($planned) : self::recipe($planned);
    }

    /** @throws ModelNotFoundException when the meal holds no entry with this id */
    public function isServing(string $id): bool
    {
        $this->entry($id);

        return $this->recipes->has(strtolower($id));
    }

    public function holds(string $id): bool
    {
        return $this->foods->has(strtolower($id)) || $this->recipes->has(strtolower($id));
    }

    public function holdsFood(string $foodId): bool
    {
        return $this->foods->contains(fn (PlannedFood $f) => strcasecmp($f->food_id, $foodId) === 0);
    }

    public function holdsRecipe(string $recipeId): bool
    {
        return $this->recipes->contains(fn (PlannedRecipe $r) => strcasecmp($r->recipe_id, $recipeId) === 0);
    }

    /** The position after the meal's last entry. */
    public function nextPosition(): int
    {
        return max([-1, ...$this->foods->pluck('position')->all(), ...$this->recipes->pluck('position')->all()]) + 1;
    }

    /** A food joining the meal at `$grams`, in the unit it is shown in (grams). */
    public function addFood(User $user, string $id, Food $food, float $grams, bool $isCooked, bool $isLocked): void
    {
        $planned = PlannedFood::query()->create([
            'id' => $id, 'user_id' => $user->id, 'meal_id' => $this->meal->id, 'food_id' => $food->id, 'position' => $this->nextPosition(),
            'grams' => $grams, 'is_cooked' => $isCooked, 'is_locked' => $isLocked, 'unit' => 'g',
        ])->setRelation('food', $food);
        $this->foods->put(strtolower($planned->id), $planned);
    }

    /** A recipe joining the meal as `$servings`, with the meal's own copy of the recipe (RCP-021). */
    public function addRecipe(User $user, string $id, Recipe $recipe, float $servings): void
    {
        $planned = PlannedRecipe::query()->create([
            'id' => $id, 'user_id' => $user->id, 'meal_id' => $this->meal->id, 'recipe_id' => $recipe->id, 'position' => $this->nextPosition(),
            'servings' => $servings, 'is_locked' => false, 'recipe' => $recipe->plannedCopy(),
        ])->setRelation('sourceRecipe', $recipe);
        $this->recipes->put(strtolower($planned->id), $planned);
    }

    /**
     * Saves the entries as the edit left them and {@see MealEdit::planned()} floored them: each one's amount and lock,
     * through its model, where either changed.
     *
     * @param  list<MealFood>  $kept
     */
    public function save(array $kept): void
    {
        foreach ($kept as $entry) {
            $key = strtolower($entry->id);
            if ($this->foods->has($key)) {
                $this->update($this->foods[$key], ['grams' => $entry->grams, 'is_locked' => $entry->isLocked]);
            } else {
                $this->update($this->recipes[$key], ['servings' => RecipeServing::servings($entry), 'is_locked' => $entry->isLocked]);
            }
        }
    }

    /** @throws ModelNotFoundException when the meal holds no entry with this id */
    public function delete(string $id): void
    {
        $this->entry($id);
        ($this->foods->pull(strtolower($id)) ?? $this->recipes->pull(strtolower($id)))->delete();
    }

    /** MEAL-009: the foods go; recipe servings stay. */
    public function deleteFoods(): void
    {
        $this->foods->each->delete();
        $this->foods = collect();
    }

    /** @param  array<string, mixed>  $values */
    private function update(PlannedFood|PlannedRecipe $row, array $values): void
    {
        $row->fill($values);
        if ($row->isDirty()) {
            $row->save();
        }
    }

    /** The food is always there: its foreign key restricts deletes and the relation includes trashed foods. */
    private static function food(PlannedFood $planned): MealFood
    {
        return new MealFood(
            id: $planned->id,
            food: MealFitFoods::of($planned->food),
            grams: $planned->grams,
            weighing: $planned->is_cooked ? Weighing::Cooked : Weighing::Raw,
            isLocked: $planned->is_locked,
        );
    }

    /** RCP-002: servings of a recipe at its ingredients' macros per serving. */
    private static function recipe(PlannedRecipe $planned): MealFood
    {
        return RecipeServing::food($planned->id, TodaySnapshot::recipeName($planned), TodaySnapshot::perServing($planned), $planned->servings, $planned->is_locked);
    }
}
