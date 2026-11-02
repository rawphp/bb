<?php

namespace App\Domain\Foods;

/** FOOD-025: the Pantry's source filter. The values are food_search's `source` input. */
enum FoodSearchSource: string
{
    case Recents = 'recents';
    case Favorites = 'favorites';
    case Curated = 'curated';
    case Custom = 'custom';
    case Restaurants = 'restaurants';
    case Packaged = 'packaged';

    /** The food kind the filter narrows every tier to, if it is a kind filter. */
    public function kind(): ?FoodKind
    {
        return match ($this) {
            self::Restaurants => FoodKind::Restaurant,
            self::Packaged => FoodKind::Packaged,
            default => null,
        };
    }

    /** Recents, Favorites and Custom are the user's own foods, so only the user's tier is searched. */
    public function ownOnly(): bool
    {
        return in_array($this, [self::Recents, self::Favorites, self::Custom], true);
    }
}
