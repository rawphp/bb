<?php

namespace Database\Seeders;

use App\Domain\MealFit\CuratedGroup;
use App\Models\Food;
use App\Models\FoodServing;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** The curated "verified" foods (FOOD-002) from data/curated_foods.php, keyed by source and slug. Safe to re-run. */
class CuratedFoodSeeder extends Seeder
{
    public function run(): void
    {
        $foods = [];
        $servings = [];
        $now = now();

        foreach (require __DIR__.'/data/curated_foods.php' as [$name, $group, $kcal, $protein, $fat, $carbs, $fiber, $ratio, $units, $diets]) {
            $slug = Str::slug($name);
            $foods[] = [
                'id' => (string) Str::ulid(),
                'name' => $name,
                'brand' => null,
                'barcode' => null,
                'source' => 'curated',
                'source_id' => $slug,
                'kind' => 'generic',
                'curated' => true,
                'curated_group' => CuratedGroup::from($group)->value,
                'kcal' => $kcal,
                'protein' => $protein,
                'fat' => $fat,
                'carbs' => $carbs,
                'fiber' => $fiber,
                'cooked_ratio' => $ratio,
                'created_at' => $now,
                'updated_at' => $now,
            ] + self::flags($diets);
            $servings[$slug] = $units;
        }

        DB::transaction(function () use ($foods, $servings) {
            Food::upsert($foods, ['source', 'source_id'], array_diff(array_keys($foods[0]), ['id', 'source', 'source_id', 'created_at']));

            $ids = Food::where('source', 'curated')->whereIn('source_id', array_keys($servings))->pluck('id', 'source_id');
            FoodServing::whereIn('food_id', $ids->values())->delete();

            $rows = [];
            foreach ($servings as $slug => $units) {
                foreach ($units as $label => $grams) {
                    $rows[] = ['food_id' => $ids[$slug], 'label' => $label, 'grams' => $grams];
                }
            }
            FoodServing::insert($rows);
        });
    }

    /** @return array<string, bool> every diet flag, true for the letters in $letters (see curated_foods.php) */
    private static function flags(string $letters): array
    {
        $flagOf = ['d' => 'dairy_free', 'g' => 'gluten_free', 'r' => 'grain_free', 'l' => 'low_fodmap',
            'v' => 'vegetarian', 'p' => 'paleo', 'n' => 'vegan'];

        $flags = array_fill_keys(Food::DIETS, false);
        foreach ($flagOf as $letter => $flag) {
            $flags[$flag] = str_contains($letters, $letter);
        }

        return $flags;
    }
}
