<?php

namespace App\Domain\MealFit;

/** FOOD-006: whether the grams of a food were weighed raw or cooked. */
enum Weighing: string
{
    case Raw = 'raw';
    case Cooked = 'cooked';
}
