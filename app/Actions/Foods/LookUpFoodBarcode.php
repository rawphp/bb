<?php

namespace App\Actions\Foods;

use App\Domain\Foods\Barcode;
use App\Domain\Foods\FoodSource;
use App\Models\Food;
use App\Models\User;

/** The Action the food_barcode_lookup capability (A_SCAN) calls from run(); the capability shapes the row. */
final class LookUpFoodBarcode
{
    /** FOOD-041: the food with this barcode: the user's own first, then curated, then Open Food Facts, then USDA. */
    public function __invoke(User $user, string $code): ?Food
    {
        $barcode = Barcode::normalize($code);
        if ($barcode === null) {
            return null;
        }

        return Food::query()->visibleTo($user)->where('barcode', $barcode)
            ->orderByRaw('case when user_id is not null then 0 when curated then 1 when source = ? then 2 else 3 end', [FoodSource::OpenFoodFacts->value])
            // On a tie, the most recently imported row: USDA gives an updated Branded product a new fdcId.
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->with('servings')
            ->first();
    }
}
