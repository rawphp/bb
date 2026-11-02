<?php

namespace App\Domain\Placement;

/**
 * From start up to end on the wall clock; crosses midnight when end is earlier than start (SCH-045).
 * Used for eating windows and busy periods.
 */
final readonly class ClockRange
{
    public function __construct(
        public ClockTime $start,
        public ClockTime $end,
    ) {}

    public function lengthInMinutes(): int
    {
        return $this->start->minutesUntil($this->end);
    }

    /**
     * Minutes from start to time, on whichever day puts it nearer the range: negative for a time just before start.
     * Orders times around a window that crosses midnight, or a time outside it.
     */
    public function offset(ClockTime $time): int
    {
        $after = $this->start->minutesUntil($time);
        $length = $this->lengthInMinutes();

        return $after <= $length || $after - $length <= ClockTime::MINUTES_PER_DAY - $after
            ? $after
            : $after - ClockTime::MINUTES_PER_DAY;
    }
}
