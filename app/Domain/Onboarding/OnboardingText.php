<?php

namespace App\Domain\Onboarding;

use App\Domain\Model\HeightUnit;
use App\Domain\Model\UnitConversion;
use App\Domain\Model\WeightUnit;
use App\Domain\Placement\ClockTime;
use DateTimeImmutable;

/**
 * The words setup writes (rp-clone OnboardingModel+Review's text helpers): the same on every device, whatever its
 * locale. Weekdays are ISO, 1 = Monday.
 */
final class OnboardingText
{
    private const WEEKDAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    /** "Oct 11, 2026". */
    public static function dateText(DateTimeImmutable $day): string
    {
        return $day->format('M j, Y');
    }

    /** "6:00 PM". Minutes outside the day are kept inside it. */
    public static function timeText(int $minutes): string
    {
        return (new ClockTime(min(max($minutes, 0), ClockTime::MINUTES_PER_DAY - 1)))->shortText();
    }

    /** "5,000". */
    public static function numberText(int $value): string
    {
        return number_format($value);
    }

    /** "1,368 kcal a day". */
    public static function kcalText(float $kcal): string
    {
        return self::numberText((int) round($kcal)).' kcal a day';
    }

    /** "Mon". */
    public static function weekdayName(int $weekday): string
    {
        return substr(self::weekdayFullName($weekday), 0, 3);
    }

    /** "Monday". */
    public static function weekdayFullName(int $weekday): string
    {
        return self::WEEKDAYS[$weekday] ?? '';
    }

    /**
     * "Mon to Fri", "Sat and Sun", "Mon, Wed, Fri": runs of neighbouring days are joined.
     *
     * @param  list<int>  $weekdays
     */
    public static function weekdaysText(array $weekdays): string
    {
        $days = array_values(array_unique($weekdays));
        sort($days);
        $runs = [];
        foreach ($days as $day) {
            $last = array_key_last($runs);
            if ($last !== null && end($runs[$last]) === $day - 1) {
                $runs[$last][] = $day;
            } else {
                $runs[] = [$day];
            }
        }

        return implode(', ', array_map(fn (array $run) => match (count($run)) {
            1 => self::weekdayName($run[0]),
            2 => self::weekdayName($run[0]).' and '.self::weekdayName($run[1]),
            default => self::weekdayName($run[0]).' to '.self::weekdayName($run[count($run) - 1]),
        }, $runs));
    }

    /** "-0.75%": the sign as TBL-010 writes it. */
    public static function rateText(float $rate): string
    {
        return ($rate > 0 ? '+' : '').sprintf('%g', $rate).'%';
    }

    /** "72.8 kg", "176.4 lb". */
    public static function weightText(float $kg, WeightUnit $unit): string
    {
        return self::oneDecimal(self::weightIn($kg, $unit)).' '.$unit->value;
    }

    /** "170 cm", "5 ft 7 in". */
    public static function heightText(float $cm, HeightUnit $unit): string
    {
        if ($unit === HeightUnit::Cm) {
            return self::oneDecimal($cm).' cm';
        }
        [$feet, $inches] = UnitConversion::cmToFeetInches($cm);
        $whole = (int) round($inches);
        if ($whole === 12) {
            $feet++;
            $whole = 0;
        }

        return "{$feet} ft {$whole} in";
    }

    /** A weight in kg in the unit on screen (ONB-004). */
    public static function weightIn(float $kg, WeightUnit $unit): float
    {
        return $unit === WeightUnit::Kg ? $kg : UnitConversion::kgToLb($kg);
    }

    /** A weight in the unit on screen in kg (ONB-004). */
    public static function kgFrom(float $value, WeightUnit $unit): float
    {
        return $unit === WeightUnit::Kg ? $value : UnitConversion::lbToKg($value);
    }

    /** "72.8", and "80" rather than "80.0". */
    private static function oneDecimal(float $value): string
    {
        $text = sprintf('%.1f', $value);

        return str_ends_with($text, '.0') ? substr($text, 0, -2) : $text;
    }
}
