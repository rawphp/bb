<?php

namespace App\Capabilities\Reads\Train;

use App\Domain\Model\PhaseType;
use DateTimeImmutable;

/** How the Train screens say dates, lengths and calories (en-GB). */
final class TrainWords
{
    public const NO_PHASE = 'No phase';

    /** "7 Sep – 11 Oct 2026". */
    public static function dates(DateTimeImmutable $first, DateTimeImmutable $last): string
    {
        return $first->format('j M').' – '.$last->format('j M Y');
    }

    /** "12 Oct 2026". */
    public static function date(DateTimeImmutable $day): string
    {
        return $day->format('j M Y');
    }

    /** "6 Oct". */
    public static function day(DateTimeImmutable $day): string
    {
        return $day->format('j M');
    }

    /** "Starts Monday 12 Oct". */
    public static function starts(DateTimeImmutable $day): string
    {
        return 'Starts '.$day->format('l j M');
    }

    /** "1 week", "5 weeks". */
    public static function weeks(int $weeks): string
    {
        return self::count($weeks, 'week');
    }

    /** "1 set", "2 days". */
    public static function count(int $count, string $noun): string
    {
        return $count === 1 ? "1 {$noun}" : "{$count} {$noun}s";
    }

    /** "Muscle gain", or "No phase". */
    public static function phase(?PhaseType $type): string
    {
        return $type?->title() ?? self::NO_PHASE;
    }

    /** "2,350 kcal/day". */
    public static function kcalPerDay(int $kcal): string
    {
        return number_format($kcal).' kcal/day';
    }
}
