<?php

namespace App\Actions\Scan;

use App\Actions\Foods\MealFitFoods;
use App\Ai\FoodAi;
use App\Ai\MatchedItem;
use App\Capabilities\Nutrition\Data\ScanAdjustRow;
use App\Domain\Scan\ScanDescription;
use App\Domain\Scan\ScanItem;
use App\Domain\Scan\ScannedMeal;
use App\Domain\Scan\ScanPortion;
use App\Models\Food;
use App\Models\User;
use DomainException;

/**
 * FOOD-043 to FOOD-045: a scanned meal from a photo, a typed description or the user's edits. The scan_photo,
 * scan_describe and scan_adjust capabilities call it from run(). Nothing is saved.
 */
final class ScanMeal
{
    public function __construct(private readonly FoodAi $ai) {}

    /** FOOD-043 */
    public function photo(User $user, string $image): ScannedMeal
    {
        return ScannedMeal::scanned($this->items($this->ai->photo($user, $image)), null);
    }

    /**
     * FOOD-044: the description, trimmed, also starts the saved meal's name (FOOD-046).
     *
     * @throws DomainException when it is blank or too long once trimmed; nothing is sent
     */
    public function describe(User $user, string $description): ScannedMeal
    {
        $text = ScanDescription::text($description);

        return ScannedMeal::scanned($this->items($this->ai->describe($user, $text)), $text);
    }

    /**
     * FOOD-043, FOOD-045: the rows as the user left them (a typed amount, a new match, a row removed), shown at
     * portion `from`, at portion `to`. A food the user cannot see is no match; the authorizer has already
     * answered 404 for one (CAP-020).
     *
     * @param  list<ScanAdjustRow>  $rows
     */
    public function adjust(User $user, array $rows, float $from, float $to, ?string $savedMealName): ScannedMeal
    {
        $ids = array_values(array_unique(array_filter(array_map(fn (ScanAdjustRow $row): ?string => $row->food_id, $rows))));
        $foods = $ids === [] ? collect() : Food::query()->visibleTo($user)->whereKey($ids)->get()->keyBy('id');

        $items = array_map(function (ScanAdjustRow $row) use ($foods, $from): ScanItem {
            $food = $row->food_id === null ? null : $foods->get($row->food_id);

            return $this->item($row->name, ScanPortion::base($row->grams, $row->base_grams, $from), $food);
        }, $rows);

        return ScannedMeal::adjusted($items, $to, $savedMealName);
    }

    /**
     * @param  list<MatchedItem>  $matches
     * @return list<ScanItem>
     */
    private function items(array $matches): array
    {
        return array_map(fn (MatchedItem $match): ScanItem => $this->item($match->name, $match->grams, $match->food), $matches);
    }

    private function item(string $name, float $baseGrams, ?Food $food): ScanItem
    {
        return new ScanItem(
            name: $name,
            baseGrams: $baseGrams,
            food: $food === null ? null : MealFitFoods::of($food),
            detail: $food?->brand,
        );
    }
}
