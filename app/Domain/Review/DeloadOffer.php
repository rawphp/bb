<?php

namespace App\Domain\Review;

/** REV-011: how Drop fatigue meets the deload. */
final readonly class DeloadOffer
{
    private function __construct(
        /** Null: only "Deload now". Otherwise "Make this your deload week too?" with this default. */
        public ?bool $askDeloadTooDefaultYes,
    ) {}

    /** Only "Deload now": the diet break and the deload are one week. */
    public static function deloadNow(): self
    {
        return new self(null);
    }

    /** "Make this your deload week too?" */
    public static function askDeloadToo(bool $defaultYes): self
    {
        return new self($defaultYes);
    }
}
