<?php

namespace Database\Seeders;

use App\Domain\Model\DietFilter;
use App\Models\Food;
use App\Models\Recipe;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The ready-made recipe library (RCP-020) from data/recipes.php, keyed by slug. Runs after CuratedFoodSeeder
 * (name order): ingredients are curated foods only. A recipe's diet flag is true when every ingredient's is
 * (FOOD-027, DietFilter::sharedBy). Safe to re-run.
 */
class RecipeSeeder extends Seeder
{
    public function run(): void
    {
        $foods = Food::where('source', 'curated')->get()->keyBy('name');

        DB::transaction(function () use ($foods) {
            foreach (require __DIR__.'/data/recipes.php' as [$name, $mealType, $servings, $ingredients, $steps]) {
                $used = array_map(
                    fn (array $ingredient) => $foods[$ingredient[0]]
                        ?? throw new RuntimeException("Recipe \"{$name}\" uses \"{$ingredient[0]}\", which is not a curated food."),
                    $ingredients,
                );

                // A library recipe in the data file is served again even if it was soft-deleted.
                $recipe = Recipe::withTrashed()->firstOrNew(['slug' => Str::slug($name)]);
                $recipe->fill(['name' => $name, 'servings' => $servings, 'meal_type' => $mealType, 'instructions' => $steps]);
                $recipe->forceFill(['deleted_at' => null]
                    + DietFilter::sharedBy(array_map(fn (Food $food) => $food->only(Food::DIETS), $used)));
                $recipe->save();

                $recipe->ingredients()->delete();
                $recipe->ingredients()->createMany(array_map(
                    fn (array $ingredient, int $position) => [
                        'food_id' => $used[$position]->id,
                        'grams' => $ingredient[1],
                        'is_cooked' => false,
                        'position' => $position,
                    ],
                    $ingredients,
                    array_keys($ingredients),
                ));
            }
        });
    }
}
