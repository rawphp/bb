<?php

namespace App\Domain\Model;

/** The diet filters that hide foods which do not match (FOOD-027, SET-006). Values are the server's. */
enum DietFilter: string
{
    case DairyFree = 'dairy_free';
    case GlutenFree = 'gluten_free';
    case GrainFree = 'grain_free';
    case LowFodmap = 'low_fodmap';
    case Vegetarian = 'vegetarian';
    case Paleo = 'paleo';
    case Vegan = 'vegan';

    /**
     * A mix of foods, such as a recipe, passes a filter only when every food in it does. A flag a food
     * does not name counts as not passing, and an empty mix passes none.
     *
     * @param  list<array<string, bool>>  $foods  each food's flags, keyed by filter value
     * @return array<string, bool> every filter's value for the mix, keyed by filter value
     */
    public static function sharedBy(array $foods): array
    {
        $flags = [];
        foreach (self::cases() as $filter) {
            $flags[$filter->value] = $foods !== [] && array_all($foods, fn (array $food): bool => $food[$filter->value] ?? false);
        }

        return $flags;
    }

    public function title(): string
    {
        return match ($this) {
            self::DairyFree => 'Dairy free',
            self::GlutenFree => 'Gluten free',
            self::GrainFree => 'Grain free',
            self::LowFodmap => 'Low FODMAP',
            self::Vegetarian => 'Ovo-lacto vegetarian',
            self::Paleo => 'Paleo',
            self::Vegan => 'Vegan',
        };
    }
}
