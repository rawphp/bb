<?php

namespace App\Actions\Scan;

use App\Ai\FoodAi;
use App\Ai\MatchedItem;
use App\Capabilities\Nutrition\Data\ScanAdjustRow;
use App\Domain\MealFit\Food as MealFitFood;
use App\Domain\Scan\ScanItem;
use App\Domain\Scan\ScannedMeal;
use App\Models\Food;
use App\Models\User;
use DomainException;

/**
 * FOOD-043 to FOOD-045: a scanned meal from a photo, a typed description or the user's edits. The scan_photo,
 * scan_describe and scan_adjust capabilities call it from run(). Nothing is saved.
 */
final class ScanMeal
{
    public const DESCRIPTION_LIMIT = 1000;

    public function __construct(private readonly FoodAi $ai) {}

    /** FOOD-043 */
    public function photo(User $user, string $image): ScannedMeal
    {
        return ScannedMeal::scanned($this->items($this->ai->photo($user, $image)), null);
    }

    /**
     * FOOD-044: the description is trimmed, and also starts the saved meal's name (FOOD-046).
     *
     * @throws DomainException when nothing is left once trimmed; nothing is sent
     */
    public function describe(User $user, string $description): ScannedMeal
    {
        $text = trim($description);
        if ($text === '') {
            throw new DomainException('Describe the meal in 1 to '.self::DESCRIPTION_LIMIT.' characters.');
        }

        return ScannedMeal::scanned($this->items($this->ai->describe($user, $text)), $text);
    }

    /**
     * FOOD-043, FOOD-045: the rows as the user left them (a typed amount, a new match, a row removed), from
     * portion `from` to portion `to`. A food the user cannot see is no match; the authorizer has already
     * answered 404 for one (CAP-020).
     *
     * @param  list<ScanAdjustRow>  $rows
     */
    public function adjust(User $user, array $rows, float $from, float $to, ?string $savedMealName): ScannedMeal
    {
        $ids = array_values(array_unique(array_filter(array_map(fn (ScanAdjustRow $row): ?string => $row->food_id, $rows))));
        $foods = $ids === [] ? collect() : Food::query()->visibleTo($user)->whereKey($ids)->get()->keyBy('id');

        $items = array_map(function (ScanAdjustRow $row) use ($foods): ScanItem {
            $food = $row->food_id === null ? null : $foods->get($row->food_id);

            return $this->item($row->name, $row->grams, $food);
        }, $rows);

        return ScannedMeal::adjusted($items, $from, $to, $savedMealName);
    }

    /**
     * @param  list<MatchedItem>  $matches
     * @return list<ScanItem>
     */
    private function items(array $matches): array
    {
        return array_map(fn (MatchedItem $match): ScanItem => $this->item($match->name, $match->grams, $match->food), $matches);
    }

    private function item(string $name, float $grams, ?Food $food): ScanItem
    {
        return new ScanItem(
            name: $name,
            grams: $grams,
            food: $food === null ? null : new MealFitFood(
                id: (string) $food->id,
                name: $food->name,
                kcal: $food->kcal,
                proteinG: $food->protein,
                fatG: $food->fat,
                carbsG: $food->carbs,
                cookedRatio: $food->cooked_ratio,
            ),
            detail: $food?->brand,
        );
    }
}
