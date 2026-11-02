<?php

namespace App\Domain\Review\DeloadMove;

use App\Domain\Review\DeloadMove;

/** REV-011: how Drop fatigue meets the deload. */
final readonly class Plan
{
    private function __construct(
        public PlanKind $kind,
        /** Only for AskDeloadToo: Yes is the default. */
        public ?bool $defaultYes,
        public ?DeloadMove $move,
    ) {}

    public static function deloadNow(DeloadMove $move): self
    {
        return new self(PlanKind::DeloadNow, null, $move);
    }

    public static function askDeloadToo(bool $defaultYes, DeloadMove $move): self
    {
        return new self(PlanKind::AskDeloadToo, $defaultYes, $move);
    }

    public static function dietBreakOnly(): self
    {
        return new self(PlanKind::DietBreakOnly, null, null);
    }
}
