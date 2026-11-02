<?php

namespace App\Actions\Foods;

use App\Domain\Foods\Barcode;
use App\Domain\Foods\CustomFoodServing;
use App\Domain\Foods\FoodKind;
use App\Domain\Foods\FoodSource;
use App\Models\Food;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * FOOD-060: create or edit the user's custom food. It is a foods row with an owner, so meals, recipes
 * and search take its id like any other food's. The row holds the serving per 100 g and keeps the
 * serving as entered, and the serving becomes the food's one named serving.
 */
final class SaveCustomFood
{
    /** @param  Food|null  $food  the user's food to edit; null creates one under $id */
    public function __invoke(User $user, ?Food $food, string $id, string $name, ?string $brand, ?string $barcode, CustomFoodServing $serving): Food
    {
        return DB::transaction(function () use ($user, $food, $id, $name, $brand, $barcode, $serving): Food {
            if ($food === null) {
                $food = new Food;
                $food->id = $id;
                $food->user_id = $user->id;
                $food->source = FoodSource::Custom->value;
                $food->source_id = $id;
                $food->kind = FoodKind::Custom->value;
                $food->curated = false;
            }

            $brand = trim((string) $brand);
            $food->fill([
                'name' => trim($name),
                'brand' => $brand === '' ? null : $brand,
                'barcode' => Barcode::normalize($barcode),
                'custom_serving' => $serving->toArray(),
                ...$serving->per100g(),
            ])->save();

            $food->servings()->delete();
            $food->servings()->create(['label' => $serving->label(), 'grams' => $serving->grams]);

            return $food;
        });
    }
}
