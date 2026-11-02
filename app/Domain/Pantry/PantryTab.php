<?php

namespace App\Domain\Pantry;

/** FOOD-020: the Pantry's tabs, in order. */
enum PantryTab: string
{
    case Curated = 'curated';
    case Recents = 'recents';
    case Favorites = 'favorites';
    case Custom = 'custom';

    /** What the empty tab's foods are called: "No recent foods yet." */
    public function foodsName(): string
    {
        return match ($this) {
            self::Curated => 'curated foods',
            self::Recents => 'recent foods',
            self::Favorites => 'favorite foods',
            self::Custom => 'custom foods',
        };
    }
}
