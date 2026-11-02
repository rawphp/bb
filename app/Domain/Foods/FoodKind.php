<?php

namespace App\Domain\Foods;

/** What a food is: the `kind` column (FOOD-025's kind filter). */
enum FoodKind: string
{
    case Generic = 'generic';
    case Packaged = 'packaged';
    case Restaurant = 'restaurant';
    case Custom = 'custom';
}
