<?php

namespace App\Domain\Model;

/** Display unit for height (SET-001). Data is stored in cm. */
enum HeightUnit: string
{
    case Cm = 'cm';
    case FeetInches = 'feetInches';
}
