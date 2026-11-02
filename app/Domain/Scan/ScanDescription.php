<?php

namespace App\Domain\Scan;

use DomainException;

/** FOOD-044: a typed description of the meal, sent instead of a photo. */
final class ScanDescription
{
    public const LIMIT = 1000;

    /**
     * The description to send: trimmed, then 1 to LIMIT characters. Spaces around it do not count.
     *
     * @throws DomainException when it is blank or too long once trimmed; nothing is sent
     */
    public static function text(string $description): string
    {
        $text = trim($description);
        $length = mb_strlen($text);
        if ($length < 1 || $length > self::LIMIT) {
            throw new DomainException('Describe the meal in 1 to '.self::LIMIT.' characters.');
        }

        return $text;
    }
}
