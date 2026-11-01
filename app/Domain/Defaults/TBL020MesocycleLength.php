<?php

namespace App\Domain\Defaults;

use App\Domain\Defaults\TBL020MesocycleLength\Row;
use App\Domain\Model\ClosedRange;
use App\Domain\Model\ExperienceLevel;

/** TBL-020 Mesocycle length by experience. Weeks include the deload week. */
final class TBL020MesocycleLength
{
    public static function row(ExperienceLevel $experience): Row
    {
        return match ($experience) {
            ExperienceLevel::Beginner => new Row(6, new ClosedRange(5, 8)),
            ExperienceLevel::Intermediate => new Row(5, new ClosedRange(5, 8)),
            ExperienceLevel::Advanced => new Row(5, new ClosedRange(4, 7)),
        };
    }

    /** Resensitization mesocycle (PH-036), any level. */
    public static function resensitization(): Row
    {
        return new Row(4, new ClosedRange(3, 4));
    }
}
