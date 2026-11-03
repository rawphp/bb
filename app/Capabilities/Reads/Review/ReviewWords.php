<?php

namespace App\Capabilities\Reads\Review;

use DateTimeInterface;

/** How the weekly review says a few things the steps share. */
final class ReviewWords
{
    /** "Mon, 5 Oct". */
    public static function day(DateTimeInterface $day): string
    {
        return $day->format('D, j M');
    }

    /** "2400 kcal". */
    public static function kcal(float $kcal): string
    {
        return (int) round($kcal).' kcal';
    }
}
