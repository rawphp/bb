<?php

namespace App\Domain\Model;

/** Metric storage to display units and back (SET-001). */
final class UnitConversion
{
    public const KG_PER_LB = 0.45359237;

    public const CM_PER_INCH = 2.54;

    public const INCHES_PER_FOOT = 12.0;

    public static function kgToLb(float $kg): float
    {
        return $kg / self::KG_PER_LB;
    }

    public static function lbToKg(float $lb): float
    {
        return $lb * self::KG_PER_LB;
    }

    /**
     * @return array{0: int, 1: float} feet and inches
     */
    public static function cmToFeetInches(float $cm): array
    {
        // Rounded to a millionth of an inch first, so 5 ft 0 in never reads as 4 ft 11.99999 in.
        $totalInches = round($cm / self::CM_PER_INCH * 1_000_000) / 1_000_000;
        $feet = (int) floor($totalInches / self::INCHES_PER_FOOT);

        return [$feet, $totalInches - $feet * self::INCHES_PER_FOOT];
    }

    public static function feetInchesToCm(int $feet, float $inches): float
    {
        return ($feet * self::INCHES_PER_FOOT + $inches) * self::CM_PER_INCH;
    }
}
