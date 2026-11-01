<?php

namespace App\Domain\Defaults;

/**
 * TBL-023 Target RIR by week, keyed by training weeks before the deload.
 * The two-week row is resensitization only (no ramp to failure).
 */
final class TBL023TargetRIR
{
    public const RIR_BY_TRAINING_WEEKS = [
        2 => [3, 2],
        3 => [3, 1, 0],
        4 => [3, 2, 1, 0],
        5 => [3, 2, 2, 1, 0],
        6 => [3, 3, 2, 2, 1, 0],
        7 => [3, 3, 2, 2, 1, 1, 0],
    ];

    /** @return ?list<int> */
    public static function rir(int $trainingWeeks): ?array
    {
        return self::RIR_BY_TRAINING_WEEKS[$trainingWeeks] ?? null;
    }
}
