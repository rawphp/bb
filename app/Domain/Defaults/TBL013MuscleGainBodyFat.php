<?php

namespace App\Domain\Defaults;

/**
 * TBL-013 Body-fat estimate during muscle gain:
 * (start fat mass + fraction x weight gained) / current trend weight.
 */
final class TBL013MuscleGainBodyFat
{
    public const FAT_FRACTION_OF_WEIGHT_GAINED = 0.5;
}
