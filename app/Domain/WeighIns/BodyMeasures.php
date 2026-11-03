<?php

namespace App\Domain\WeighIns;

use App\Domain\Model\HeightUnit;
use App\Domain\Model\UnitConversion;
use DomainException;

/**
 * Body weight and waist between the user's units and storage (CAP-015, SET-001, WT-021). Weight is stored in kg to
 * 2 decimals and shown to 1; waist in cm to 1 decimal, shown in the height unit's family. Ported from rp-clone's
 * WeighInEntryModel and WeightDisplay.
 */
final class BodyMeasures
{
    /** The largest weight the weigh_ins table stores. */
    public const MAX_KG = 999.99;

    public const WEIGHT_LIMIT = 'Enter a weight above 0 and up to 999.99 kg.';

    /** WT-002: the weight in kg as stored. The limit applies in kg, not in the pounds typed. */
    public static function kg(float $value, string $unit): float
    {
        $kg = round($unit === 'lb' ? UnitConversion::lbToKg($value) : $value, 2);
        if ($kg <= 0 || $kg > self::MAX_KG) {
            throw new DomainException(self::WEIGHT_LIMIT);
        }

        return $kg;
    }

    /** WT-021: the waist in cm as stored. */
    public static function waistCm(float $value, string $unit): float
    {
        return round($unit === 'in' ? $value * UnitConversion::CM_PER_INCH : $value, 1);
    }

    /** WT-021: inches for a user who sees height in feet and inches, else cm. */
    public static function waistUnit(string $heightUnit): string
    {
        return $heightUnit === HeightUnit::FeetInches->value ? 'in' : 'cm';
    }

    /** "81.4 kg": one decimal in the user's unit. */
    public static function weightLabel(float $kg, string $unit): string
    {
        return number_format(self::weightIn($kg, $unit), 1, '.', '').' '.$unit;
    }

    /** "81.65", "200": the edit field's start, up to 2 decimals without trailing zeros. */
    public static function weightField(float $kg, string $unit): string
    {
        return self::trimmed(self::weightIn($kg, $unit), 2);
    }

    /** "86 cm", "86.5 cm". */
    public static function waistLabel(float $cm, string $unit): string
    {
        return self::waistField($cm, $unit).' '.$unit;
    }

    /** "86", "86.5": one decimal without a trailing zero. */
    public static function waistField(float $cm, string $unit): string
    {
        return self::trimmed($unit === 'in' ? $cm / UnitConversion::CM_PER_INCH : $cm, 1);
    }

    private static function weightIn(float $kg, string $unit): float
    {
        return $unit === 'lb' ? UnitConversion::kgToLb($kg) : $kg;
    }

    private static function trimmed(float $value, int $decimals): string
    {
        $text = number_format($value, $decimals, '.', '');

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }
}
