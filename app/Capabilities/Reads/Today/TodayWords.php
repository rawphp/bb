<?php

namespace App\Capabilities\Reads\Today;

use App\Capabilities\Reads\Data\Today\MacroLine;
use App\Domain\Model\MealTarget;
use DateTimeImmutable;

/** How Today words its dates, amounts and counts (CAP-015). */
final class TodayWords
{
    /** "Wednesday, Sep 23". */
    public static function title(DateTimeImmutable $day): string
    {
        return $day->format('l, M j');
    }

    /** "Thu, Sep 24". */
    public static function shortDate(DateTimeImmutable $day): string
    {
        return $day->format('D, M j');
    }

    /** "Starts Saturday, Oct 3". */
    public static function starts(DateTimeImmutable $day): string
    {
        return 'Starts '.$day->format('l, M j');
    }

    /** "1 set", "4 sets". */
    public static function count(int $count, string $noun): string
    {
        return $count === 1 ? "1 {$noun}" : "{$count} {$noun}s";
    }

    /** "512 kcal · P 40 F 12 C 60" as its parts, and the line read aloud. */
    public static function macros(MealTarget $macros): MacroLine
    {
        [$kcal, $protein, $fat, $carbs] = array_map(
            fn (float $value) => (int) round($value),
            [$macros->kcal, $macros->proteinG, $macros->fatG, $macros->carbsG],
        );

        return new MacroLine(
            kcal: "{$kcal} kcal",
            protein: "P {$protein}",
            fat: "F {$fat}",
            carbs: "C {$carbs}",
            label: "{$kcal} kcal, {$protein} grams protein, {$fat} grams fat, {$carbs} grams carbs",
        );
    }

    /** "6" for 6000 steps, "7.5" for 7500. */
    public static function thousands(int $steps): string
    {
        return $steps % 1000 === 0 ? (string) intdiv($steps, 1000) : number_format($steps / 1000, 1);
    }

    /** "5,432 steps", "1 step". */
    public static function steps(int $count): string
    {
        return $count === 1 ? '1 step' : number_format($count).' steps';
    }
}
