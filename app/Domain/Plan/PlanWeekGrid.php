<?php

namespace App\Domain\Plan;

use App\Domain\Model\Plan;
use App\Domain\Model\PlanWeek;
use App\Domain\Model\ReviewDayChange;
use DateTimeImmutable;
use DateTimeZone;
use Generator;
use InvalidArgumentException;

/**
 * The plan's week grid (PLAN-008). Week 0 contains the plan start; every later week starts on the review day.
 * A plan that starts mid-week has a short first week (1 to 6 days).
 *
 * Changing the review day never touches the week in progress. From the next boundary the new day applies:
 * the transition week is shortened, or lengthened by 7 days when the cut would leave it 1 to 3 days long.
 *
 * Days are counted in the time zone of the plan start; every date passed in is read in that zone.
 * Weekdays run 1 = Sunday ... 7 = Saturday.
 */
final readonly class PlanWeekGrid
{
    private DateTimeZone $zone;

    private DateTimeImmutable $start;

    /** @var list<ReviewDayChange> */
    private array $changes;

    /**
     * @param  list<ReviewDayChange>  $reviewDayChanges  in date order, each from the first day of its transition week
     */
    public function __construct(DateTimeImmutable $start, private int $startReviewWeekday, array $reviewDayChanges = [])
    {
        $this->zone = $start->getTimezone();
        $this->start = $start->setTime(0, 0);
        $this->changes = array_map(
            fn (ReviewDayChange $change) => new ReviewDayChange($this->day($change->from), $change->weekday),
            $reviewDayChanges,
        );
    }

    /** The grid of a plan: its starting review day, then each stored review-day change (PLAN-008). */
    public static function forPlan(Plan $plan): self
    {
        return new self($plan->start, $plan->reviewWeekday, $plan->reviewDayChanges);
    }

    /** The review weekday now in force (the latest change). */
    public function reviewWeekday(): int
    {
        return $this->changes === [] ? $this->startReviewWeekday : $this->changes[count($this->changes) - 1]->weekday;
    }

    /** The review weekday the plan started on. */
    public function startReviewWeekday(): int
    {
        return $this->startReviewWeekday;
    }

    /**
     * The review-day changes, as a Plan stores them.
     *
     * @return list<ReviewDayChange>
     */
    public function reviewDayChanges(): array
    {
        return $this->changes;
    }

    /** PLAN-008: the new review day applies from the first week boundary after `$requestedOn`. */
    public function changingReviewDay(int $weekday, DateTimeImmutable $requestedOn): self
    {
        $day = $this->day($requestedOn);
        if ($day < $this->start) {
            return new self($this->start, $weekday);
        }

        $boundary = $this->start;
        foreach ($this->weekStarts() as $start) {
            $boundary = $start;
            if ($start > $day) {
                break;
            }
        }

        $kept = array_values(array_filter($this->changes, fn (ReviewDayChange $change) => $change->from < $boundary));
        $kept[] = new ReviewDayChange($boundary, $weekday);

        return new self($this->start, $this->startReviewWeekday, $kept);
    }

    /** The first day of a week, at the start of the day. `$index` must be 0 or more. */
    public function start(int $index): DateTimeImmutable
    {
        if ($index < 0) {
            throw new InvalidArgumentException('week index must not be negative');
        }
        foreach ($this->weekStarts() as $week => $start) {
            if ($week === $index) {
                return $start;
            }
        }

        throw new InvalidArgumentException('week index out of range');
    }

    /** The week as a PlanWeek with its true length; callers set the deload, diet-break and away flags. */
    public function planWeek(int $index): PlanWeek
    {
        return new PlanWeek($this->start($index), $this->lengthInDays($index));
    }

    public function lengthInDays(int $index): int
    {
        return self::daysBetween($this->start($index), $this->start($index + 1));
    }

    /** The week a date falls in, or null before the plan start. */
    public function weekIndexContaining(DateTimeImmutable $date): ?int
    {
        $day = $this->day($date);
        if ($day < $this->start) {
            return null;
        }
        $index = 0;
        foreach ($this->weekStarts() as $week => $start) {
            if ($start > $day) {
                break;
            }
            $index = $week;
        }

        return $index;
    }

    /**
     * PLAN-002: the week a date falls in; before the plan start, minus the whole weeks back to it, so a date less
     * than a week before the start falls in week 0.
     */
    public function weekIndexCountingBack(DateTimeImmutable $date): int
    {
        return $this->weekIndexContaining($date)
            ?? -intdiv(self::daysBetween($this->day($date), $this->start), 7);
    }

    /** True when `$date` is the first day of some week. */
    public function isBoundary(DateTimeImmutable $date): bool
    {
        $day = $this->day($date);
        if ($day < $this->start) {
            return false;
        }
        foreach ($this->weekStarts() as $start) {
            if ($start >= $day) {
                return $start == $day;
            }
        }

        return false;
    }

    /**
     * Every week start in order, keyed by week index. Endless: callers stop when they have their answer.
     *
     * @return Generator<int, DateTimeImmutable>
     */
    private function weekStarts(): Generator
    {
        $segments = [new ReviewDayChange($this->start, $this->startReviewWeekday), ...$this->changes];
        $week = 0;
        foreach ($segments as $offset => $segment) {
            $limit = $segments[$offset + 1]->from ?? null;
            yield $week++ => $segment->from;
            $next = $this->firstBoundary($segment, mayLengthen: $offset > 0);
            while ($limit === null || $next < $limit) {
                yield $week++ => $next;
                $next = $next->modify('+7 days');
            }
        }
    }

    private function firstBoundary(ReviewDayChange $segment, bool $mayLengthen): DateTimeImmutable
    {
        $current = (int) $segment->from->format('w') + 1;
        $days = (($segment->weekday - $current) % 7 + 7) % 7;
        if ($days === 0) {
            $days = 7;
        }
        if ($mayLengthen && $days <= 3) {
            $days += 7;
        }

        return $segment->from->modify("+{$days} days");
    }

    /** The start of the day a date falls on, in the grid's time zone. */
    private function day(DateTimeImmutable $date): DateTimeImmutable
    {
        return $date->setTimezone($this->zone)->setTime(0, 0);
    }

    /** Calendar days from one start of day to another, whatever daylight saving does in between. */
    private static function daysBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $utc = new DateTimeZone('UTC');
        $fromDay = new DateTimeImmutable($from->format('Y-m-d'), $utc);
        $toDay = new DateTimeImmutable($to->format('Y-m-d'), $utc);

        return intdiv($toDay->getTimestamp() - $fromDay->getTimestamp(), 86400);
    }
}
