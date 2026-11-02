<?php

namespace App\Domain\Foods;

/** The lookup key for a barcode (FOOD-041), so a scanned code finds the stored product. */
final class Barcode
{
    /**
     * Digits only with leading zeros stripped, so the UPC-12 and EAN-13 of one
     * product share a key. Returns null for anything that is not a barcode, including
     * codes longer than 14 digits (the longest GTIN).
     */
    public static function normalize(?string $code): ?string
    {
        $code = trim((string) $code);
        if ($code === '' || ! ctype_digit($code)) {
            return null;
        }

        $code = ltrim($code, '0');

        return $code === '' || strlen($code) > 14 ? null : $code;
    }
}
