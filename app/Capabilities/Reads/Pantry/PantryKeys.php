<?php

namespace App\Capabilities\Reads\Pantry;

use App\Capabilities\Support\ReadKey;
use App\Domain\Pantry\PantryTab;

/**
 * The food_search read keys a write that changes the user's foods can name (CAP-010). A food_search key holds its
 * whole input (a search's text, the meal, the unit), so no write can name them all: these are the Pantry as it opens,
 * and each tab with no other input. A read cached under any other input is refreshed by the client's next read.
 */
final class PantryKeys
{
    /** @return list<string> */
    public static function opened(): array
    {
        return [
            ReadKey::for('food_search'),
            ...array_map(fn (PantryTab $tab) => ReadKey::for('food_search', ['tab' => $tab->value]), PantryTab::cases()),
        ];
    }
}
