<?php

namespace App\Domain\Copy;

/** MEAL-060: the three advanced options of Copy foods and Copy day, with their defaults. Copy week takes the defaults. */
final readonly class CopyOptions
{
    /**
     * @param  bool  $updateTargetMacros  The copies re-fit to each destination meal's own targets; locked ones keep their amounts (MEAL-005).
     * @param  bool  $updateMealTimes  Each destination meal takes the source meal's time; an intra-workout shake keeps its own.
     * @param  bool  $copyLockedState  The copies keep the source's locks; otherwise they arrive unlocked.
     */
    public function __construct(
        public bool $updateTargetMacros = true,
        public bool $updateMealTimes = false,
        public bool $copyLockedState = false,
    ) {}
}
