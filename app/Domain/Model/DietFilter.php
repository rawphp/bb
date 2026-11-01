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
