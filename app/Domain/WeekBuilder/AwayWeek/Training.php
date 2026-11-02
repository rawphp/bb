<?php

namespace App\Domain\WeekBuilder\AwayWeek;

/** SCH-060: what an away week does to training. */
enum Training: string
{
    case Pause = 'pause';
    case Deload = 'deload';
}
