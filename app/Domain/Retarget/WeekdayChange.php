<?php

namespace App\Domain\Retarget;

use App\Domain\Retarget\WeekdayChange\Kind;

/** SCH-039: what a change of training weekdays mid-mesocycle asks of the user. */
final readonly class WeekdayChange
{
    /**
     * @param  list<WeekdayChoice>  $choices  The user picks one, applied with `TrainingWeekdays::restructured` or
     *                                        `TrainingWeekdays::keepingAllSessions`.
     */
    private function __construct(
        public Kind $kind,
        public array $choices = [],
    ) {}

    /** The same number of days: the sessions re-map in order onto the new weekdays (SCH-040), and the mesocycle is unchanged. */
    public static function remap(): self
    {
        return new self(Kind::Remap);
    }

    /** @param  list<WeekdayChoice>  $choices */
    public static function choose(array $choices): self
    {
        return new self(Kind::Choose, $choices);
    }
}
