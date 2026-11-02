<?php

namespace App\Support\Today;

use DateTimeImmutable;

/** How the Today sheets write numbers and days (CAP-015): "2,400 kcal", "170 g protein", "Wed, Sep 23". */
final class TodayWords
{
    public static function kcal(float $kcal): string
    {
        return number_format(round($kcal)).' kcal';
    }

    public static function protein(float $grams): string
    {
        return number_format(round($grams)).' g protein';
    }

    public static function day(DateTimeImmutable $date): string
    {
        return $date->format('D, M j');
    }

    public static function weekday(DateTimeImmutable $date): string
    {
        return $date->format('l');
    }
}
