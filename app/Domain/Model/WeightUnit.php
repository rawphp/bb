<?php

namespace App\Domain\Model;

/** Display unit for body weight and loads (SET-001). Data is stored in kg. */
enum WeightUnit: string
{
    case Kg = 'kg';
    case Lb = 'lb';
}
