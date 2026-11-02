<?php

namespace App\Capabilities\Nutrition;

use App\Actions\Foods\LookUpFoodBarcode;
use App\Actions\Foods\MealFitFoods;
use App\Capabilities\HostCapability;
use App\Capabilities\Nutrition\Data\FoodBarcodeLookupInput;
use App\Capabilities\Nutrition\Data\FoodBarcodeLookupOutput;
use App\Capabilities\Nutrition\Data\FoodRow;
use App\Capabilities\Nutrition\Data\Macros;
use App\Capabilities\Support\HostDefinition;
use App\Domain\MealFit\MealFood;
use App\Domain\MealFit\Weighing;
use App\Domain\Model\MealTarget;
use App\Models\Food;
use App\Models\FoodFavorite;
use App\Models\User;
use Rawphp\Capabilities\Registry\CapabilityDefinitionBuilder;
use Rawphp\Capabilities\Support\CapabilityContext;

final class FoodBarcodeLookupCapability implements HostCapability
{
    public function __construct(private readonly LookUpFoodBarcode $lookUp) {}

    public function definition(): CapabilityDefinitionBuilder
    {
        return HostDefinition::read('food_barcode_lookup')
            ->description('Find the product with a scanned or typed barcode: the user\'s own foods first, then the food database. Use it when the user scans a barcode; add a found food with meal_food_add, and for a code it does not know, offer scan_label or custom_food_save with the barcode filled in. It changes nothing.')
            ->cli('food', 'barcode-lookup')
            ->input(FoodBarcodeLookupInput::class)
            ->output(FoodBarcodeLookupOutput::class)
            ->run(function (FoodBarcodeLookupInput $input, CapabilityContext $context): FoodBarcodeLookupOutput {
                /** @var User $user */
                $user = $context->user();

                return $this->run($user, $input->barcode);
            });
    }

    /** FOOD-041 */
    private function run(User $user, string $barcode): FoodBarcodeLookupOutput
    {
        $food = ($this->lookUp)($user, $barcode);

        if ($food === null) {
            return new FoodBarcodeLookupOutput(
                barcode: $barcode,
                status: 'not_found',
                food: null,
                message: "We couldn't find {$barcode}. Scan the label, or create a custom food with this barcode.",
            );
        }

        return new FoodBarcodeLookupOutput(barcode: $barcode, status: 'found', food: $this->row($user, $food), message: null);
    }

    /** FOOD-006: cooked values are per 100 g cooked, so the toggle swaps text; a food that keeps its weight has none. */
    private function row(User $user, Food $food): FoodRow
    {
        $mealFit = MealFitFoods::of($food);
        $raw = new MealFood($mealFit->id, $mealFit, 100, Weighing::Raw);
        $cooked = new MealFood($mealFit->id, $mealFit, 100, Weighing::Cooked);

        return new FoodRow(
            food_id: $mealFit->id,
            name: $food->name,
            detail: $food->brand,
            raw: self::macros($raw->macros()),
            cooked: $cooked->rawGrams() === $raw->rawGrams() ? null : self::macros($cooked->macros()),
            basis_text: 'per 100 g',
            is_curated: (bool) $food->curated,
            is_favorite: FoodFavorite::query()->where('user_id', $user->id)->where('food_id', $food->id)->exists(),
            // The lookup is not tied to a meal, so it marks none (FOOD-028).
            is_in_meal: false,
        );
    }

    /** "380 kcal", "P 22.5": calories whole, macros to one decimal. */
    private static function macros(MealTarget $per100g): Macros
    {
        $macro = fn (float $grams): string => sprintf('%g', round($grams, 1));

        return new Macros(
            kcal: ScanDrafts::kcal($per100g->kcal),
            protein: 'P '.$macro($per100g->proteinG),
            fat: 'F '.$macro($per100g->fatG),
            carbs: 'C '.$macro($per100g->carbsG),
        );
    }
}
