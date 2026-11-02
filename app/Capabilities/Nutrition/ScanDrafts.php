<?php

namespace App\Capabilities\Nutrition;

use App\Capabilities\Nutrition\Data\Macros;
use App\Capabilities\Nutrition\Data\SavedMealFood;
use App\Capabilities\Nutrition\Data\ScanDraft;
use App\Capabilities\Nutrition\Data\ScanRow;
use App\Domain\MealFit\MealFood;
use App\Domain\Model\MealTarget;
use App\Domain\Scan\ScanItem;
use App\Domain\Scan\ScannedMeal;
use App\Domain\Scan\ScanPortion;

/** The scanned meal as scan_photo, scan_describe and scan_adjust return it, ready to show (CAP-015). */
final class ScanDrafts
{
    public const NO_MATCH = 'No match';

    /**
     * @template T of ScanDraft
     *
     * @param  class-string<T>  $class
     * @return T
     */
    public static function of(string $class, ScannedMeal $meal): ScanDraft
    {
        return new $class(
            rows: array_map(fn (ScanItem $item): ScanRow => new ScanRow(
                name: $item->name,
                grams: $meal->grams($item),
                base_grams: $item->baseGrams,
                grams_text: sprintf('%g g', $meal->grams($item)),
                food_id: $item->food?->id,
                match_text: $item->food === null ? self::NO_MATCH : $item->food->name,
                detail: $item->detail,
                kcal_text: ($kcal = $meal->kcal($item)) === null ? null : self::kcal($kcal),
            ), $meal->items),
            portion: $meal->portion,
            portion_text: ScanPortion::text($meal->portion),
            portion_down: ScanPortion::down($meal->portion),
            portion_up: ScanPortion::up($meal->portion),
            totals: self::totals($meal->totals()),
            unmatched_note: $meal->unmatchedNote(),
            matched: array_map(fn (MealFood $food): SavedMealFood => new SavedMealFood($food->id, $food->grams), $meal->matched()),
            saved_meal_name: $meal->savedMealName,
            empty_text: $meal->emptyText(),
        );
    }

    /** "1,010 kcal". */
    public static function kcal(float $kcal): string
    {
        return number_format(round($kcal)).' kcal';
    }

    /** The meal's totals to whole numbers: "410 kcal", "P 14", "F 6", "C 73". */
    private static function totals(MealTarget $totals): Macros
    {
        return new Macros(
            kcal: self::kcal($totals->kcal),
            protein: 'P '.round($totals->proteinG),
            fat: 'F '.round($totals->fatG),
            carbs: 'C '.round($totals->carbsG),
        );
    }
}
