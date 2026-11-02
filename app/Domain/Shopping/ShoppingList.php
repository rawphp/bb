<?php

namespace App\Domain\Shopping;

use DateTimeImmutable;
use DateTimeZone;

/** SHOP-001 to SHOP-004: the shopping list from the foods planned over a date range. */
final class ShoppingList
{
    /** SHOP-001: the default range is this many days, starting today. */
    public const int DEFAULT_DAYS = 7;

    /**
     * SHOP-003 [G]: purchase steps. Up to `upTo` grams, amounts round up to the next `step`.
     *
     * @var list<array{upTo: float, step: float}>
     */
    public const array PURCHASE_STEPS = [
        ['upTo' => 100.0, 'step' => 10.0],
        ['upTo' => 1000.0, 'step' => 50.0],
        ['upTo' => INF, 'step' => 100.0],
    ];

    /** SHOP-001: today and the 6 days after it, as start-of-day dates in `$timezone`. */
    public static function defaultRange(DateTimeImmutable $today, DateTimeZone $timezone): DateRange
    {
        $first = self::startOfDay($today, $timezone);

        return new DateRange($first, $first->modify('+'.(self::DEFAULT_DAYS - 1).' days'));
    }

    /** SHOP-003: grams rounded up to the next friendly purchase amount. */
    public static function purchaseGrams(float $grams): float
    {
        if ($grams <= 0) {
            return 0.0;
        }

        foreach (self::PURCHASE_STEPS as ['upTo' => $upTo, 'step' => $step]) {
            if ($grams <= $upTo) {
                // Ignore floating-point noise, so 300.00000000000006 g stays 300 g.
                return ceil($grams / $step - 1e-9) * $step;
            }
        }

        return $grams;
    }

    /**
     * The list for the planned days that fall on `$days` (whole calendar days in `$timezone`, both ends
     * included): every food summed across days in raw grams (SHOP-002, SHOP-004), rounded up once
     * (SHOP-003), grouped by store category and sorted by name. Empty groups are left out.
     *
     * @param  list<PlannedFoodDay>  $planned
     * @return list<ShoppingGroup>
     */
    public static function build(array $planned, DateRange $days, DateTimeZone $timezone): array
    {
        $first = self::dayKey($days->lower, $timezone);
        $last = self::dayKey($days->upper, $timezone);

        /** @var array<string, ShoppingFood> $foods */
        $foods = [];
        /** @var array<string, float> $rawGrams */
        $rawGrams = [];
        foreach ($planned as $day) {
            $key = self::dayKey($day->date, $timezone);
            if ($key < $first || $key > $last) {
                continue;
            }
            foreach ($day->items as $item) {
                foreach ($item->foodAmounts() as $amount) {
                    // A negative amount would take grams off the same food planned elsewhere.
                    $raw = $amount->rawGrams();
                    if ($raw <= 0) {
                        continue;
                    }
                    $id = $amount->food->id;
                    $foods[$id] ??= $amount->food;
                    $rawGrams[$id] = ($rawGrams[$id] ?? 0.0) + $raw;
                }
            }
        }

        $groups = [];
        foreach (StoreCategory::cases() as $category) {
            $items = [];
            foreach ($foods as $id => $food) {
                $grams = self::purchaseGrams($rawGrams[$id]);
                if ($food->category === $category && $grams > 0) {
                    $items[] = new ShoppingItem($food, $grams);
                }
            }
            if ($items === []) {
                continue;
            }
            usort($items, fn (ShoppingItem $a, ShoppingItem $b) => strnatcasecmp($a->food->name, $b->food->name));
            $groups[] = new ShoppingGroup($category, $items);
        }

        return $groups;
    }

    private static function startOfDay(DateTimeImmutable $date, DateTimeZone $timezone): DateTimeImmutable
    {
        return $date->setTimezone($timezone)->setTime(0, 0);
    }

    /** The calendar day in `$timezone`, as Y-m-d, which sorts as text. */
    private static function dayKey(DateTimeImmutable $date, DateTimeZone $timezone): string
    {
        return $date->setTimezone($timezone)->format('Y-m-d');
    }
}
