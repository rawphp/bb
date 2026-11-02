<?php

namespace Database\Seeders;

use App\Models\Food;
use App\Models\Recipe;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The ready-made recipe library (RCP-020) from data/recipes.php, keyed by slug. Runs after CuratedFoodSeeder
 * (name order): ingredients are curated foods only. A recipe's diet flag is true when every ingredient's is
 * (FOOD-027). Safe to re-run.
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

                $recipe = Recipe::firstOrNew(['slug' => Str::slug($name)]);
                $recipe->fill(['name' => $name, 'servings' => $servings, 'meal_type' => $mealType, 'instructions' => $steps]);
                foreach (Food::DIETS as $diet) {
                    $recipe->{$diet} = collect($used)->every(fn (Food $food) => (bool) $food->{$diet});
                }
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
