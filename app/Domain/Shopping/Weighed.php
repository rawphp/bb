<?php

namespace App\Domain\Shopping;

/** FOOD-006: whether an amount was weighed raw or cooked. */
enum Weighed: string
{
    case Raw = 'raw';
    case Cooked = 'cooked';
}
