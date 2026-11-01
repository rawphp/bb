<?php

namespace App\Domain\Model;

use DateTimeImmutable;

/**
 * One plan week: starts on the review day. A full week is 7 days; the first week and a review-day transition week
 * can be shorter or longer (PLAN-008). Days follow the time zone of start.
 */
final readonly class PlanWeek
{
    public const LENGTH_IN_DAYS = 7;

    public function __construct(
        public DateTimeImmutable $start,
        public int $dayCount = self::LENGTH_IN_DAYS,
        public bool $isDeload = false,
        public bool $isDietBreak = false,
        public bool $isAway = false,
    ) {}

    /**
     * The days of the week, each at the start of its day.
     *
     * @return list<DateTimeImmutable>
     */
    public function days(): array
    {
        $first = $this->start->setTime(0, 0);
        $days = [];
        for ($offset = 0; $offset < $this->dayCount; $offset++) {
            $days[] = $first->modify("+{$offset} days");
        }

        return $days;
    }

    /** The last day of the week. */
    public function end(): ?DateTimeImmutable
    {
        $days = $this->days();

        return $days === [] ? null : $days[count($days) - 1];
    }
}
