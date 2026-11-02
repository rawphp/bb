<?php

namespace App\Domain\Progression;

/** What to show after the lifter enters a weight: a rep target (PROG-024) or only the target RIR (PROG-025). */
final readonly class Retarget
{
    public function __construct(
        public RetargetKind $kind,
        public int $value,
    ) {}

    public static function reps(int $reps): self
    {
        return new self(RetargetKind::Reps, $reps);
    }

    public static function rirOnly(int $rir): self
    {
        return new self(RetargetKind::RirOnly, $rir);
    }
}
