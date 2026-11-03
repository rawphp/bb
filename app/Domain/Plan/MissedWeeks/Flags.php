<?php

namespace App\Domain\Plan\MissedWeeks;

/** The deload flags stored on a plan week: it is a deload, it holds a diet break, and its diet break is turned off (SCH-058). */
final readonly class Flags
{
    public function __construct(
        public bool $deload,
        public bool $dietBreak,
        public bool $dietBreakOff,
    ) {}

    /** What travels with a deload when a restart moves it: the deload and its diet break, and a turned-off diet break. */
    public function carried(): self
    {
        return new self($this->deload, $this->deload && $this->dietBreak, $this->dietBreakOff);
    }
}
