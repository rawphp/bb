<?php

namespace App\Domain\Placement;

use Stringable;

/** A local wall-clock time of day, to the minute, in the user's time zone. */
final readonly class ClockTime implements Stringable
{
    public const MINUTES_PER_DAY = 24 * 60;

    /** 0 to 1439. */
    public int $minuteOfDay;

    /** Wraps around midnight: 1440 is 00:00 and -30 is 23:30. */
    public function __construct(int $minuteOfDay)
    {
        $wrapped = $minuteOfDay % self::MINUTES_PER_DAY;
        $this->minuteOfDay = $wrapped < 0 ? $wrapped + self::MINUTES_PER_DAY : $wrapped;
    }

    public static function at(int $hour, int $minute = 0): self
    {
        return new self($hour * 60 + $minute);
    }

    /**
     * Reads a stored "HH:mm" as the Swift reader does: the digits before the first colon are the hour and the digits
     * after it the minute, padded or not ("7:5" is 07:05), and anything after a second colon, such as seconds, is
     * ignored. Null for anything else, and for an hour over 23 or a minute over 59.
     */
    public static function fromHhmm(string $hhmm): ?self
    {
        if (preg_match('/^(\d+):(\d+)(:|\z)/', $hhmm, $parts) !== 1) {
            return null;
        }
        $hour = (int) $parts[1];
        $minute = (int) $parts[2];

        return $hour < 24 && $minute < 60 ? self::at($hour, $minute) : null;
    }

    public function hour(): int
    {
        return intdiv($this->minuteOfDay, 60);
    }

    public function minute(): int
    {
        return $this->minuteOfDay % 60;
    }

    public function adding(int $minutes): self
    {
        return new self($this->minuteOfDay + $minutes);
    }

    /** Minutes forward from this time to later, 0 to 1439, across midnight if needed. */
    public function minutesUntil(self $later): int
    {
        return (new self($later->minuteOfDay - $this->minuteOfDay))->minuteOfDay;
    }

    /** "17:30". */
    public function __toString(): string
    {
        return sprintf('%02d:%02d', $this->hour(), $this->minute());
    }

    /** "5:30 PM": how the app shows a time of day. */
    public function shortText(): string
    {
        $twelve = $this->hour() % 12 === 0 ? 12 : $this->hour() % 12;

        return sprintf('%d:%02d %s', $twelve, $this->minute(), $this->hour() < 12 ? 'AM' : 'PM');
    }
}
