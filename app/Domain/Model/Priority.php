<?php

namespace App\Domain\Model;

/** Muscle priority within a mesocycle (TBL-012, PROG-010). */
enum Priority: string
{
    case Emphasize = 'emphasize';
    case Grow = 'grow';
    case Maintain = 'maintain';

    /** MESO-030: bars shown for the priority. */
    public function bars(): int
    {
        return match ($this) {
            self::Emphasize => 3,
            self::Grow => 2,
            self::Maintain => 1,
        };
    }
}
