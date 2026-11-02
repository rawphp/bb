<?php

namespace App\Domain\Retarget;

use App\Domain\Retarget\MissedDayOption\Kind;
use DateTimeImmutable;

/** SCH-057: what to offer the morning after a missed training day. */
final readonly class MissedDayOption
{
    private function __construct(
        public Kind $kind,
        public ?DateTimeImmutable $date = null,
    ) {}

    public static function doItToday(): self
    {
        return new self(Kind::DoItToday);
    }

    public static function nextRestDay(DateTimeImmutable $date): self
    {
        return new self(Kind::NextRestDay, $date);
    }

    public static function skip(): self
    {
        return new self(Kind::Skip);
    }
}
