<?php

namespace App\Domain\Shopping;

use DateTimeImmutable;
use DateTimeZone;

/**
 * SHOP-005: the lines ticked off, and the date range they were ticked in. Ticks belong to one range
 * at a time: a tick in another range starts that range with no other ticks. Days compare as
 * calendar dates, so a range's times of day do not matter.
 */
final readonly class TickedRange
{
    private const string KEY_PATTERN = '/^(food|manual):[0-9a-hjkmnp-tv-z]{26}$/i';

    /** @param  list<string>  $keys  "food:<id>" and "manual:<id>" */
    public function __construct(
        public DateRange $range,
        public array $keys,
    ) {}

    public static function foodKey(string $foodId): string
    {
        return "food:{$foodId}";
    }

    public static function manualKey(string $itemId): string
    {
        return "manual:{$itemId}";
    }

    /** The manual item a key names; null for a food's key. */
    public static function manualId(string $key): ?string
    {
        return str_starts_with($key, 'manual:') ? substr($key, strlen('manual:')) : null;
    }

    /** A line's key: a food or a manual item, by its ULID. */
    public static function isKey(string $key): bool
    {
        return preg_match(self::KEY_PATTERN, $key) === 1;
    }

    /**
     * SHOP-001, SHOP-005: with no range asked for, the ticked range comes back while it still runs through
     * today; otherwise the list shows the next 7 days from today.
     */
    public static function rangeToShow(?self $saved, DateTimeImmutable $today, DateTimeZone $timezone): DateRange
    {
        if ($saved !== null && $saved->range->upper->format('Y-m-d') >= $today->setTimezone($timezone)->format('Y-m-d')) {
            return $saved->range;
        }

        return ShoppingList::defaultRange($today, $timezone);
    }

    /**
     * SHOP-005, OFF-030: tick a line off, or take the tick back. The tick names the state it leaves, so the
     * same tick twice changes nothing. A tick in another range clears the ticks of the old one; an untick
     * there (a stale one replayed from the outbox) leaves them alone.
     */
    public static function check(?self $saved, DateRange $range, string $key, bool $ticked): self
    {
        if (! $ticked && $saved !== null && ! $saved->isFor($range)) {
            return $saved;
        }

        $keys = array_values(array_filter($saved?->keysIn($range) ?? [], fn (string $kept) => $kept !== $key));

        return new self($range, $ticked ? [...$keys, $key] : $keys);
    }

    /**
     * SHOP-005: the keys ticked in `$range`; none when the ticks belong to another range.
     *
     * @return list<string>
     */
    public function keysIn(DateRange $range): array
    {
        return $this->isFor($range) ? $this->keys : [];
    }

    /** Whether these ticks were made in `$range`: the same first and last day. */
    public function isFor(DateRange $range): bool
    {
        return $this->range->lower->format('Y-m-d') === $range->lower->format('Y-m-d')
            && $this->range->upper->format('Y-m-d') === $range->upper->format('Y-m-d');
    }

    /** SHOP-005: a deleted manual item takes its tick with it. */
    public function without(string $key): self
    {
        return new self($this->range, array_values(array_filter($this->keys, fn (string $kept) => $kept !== $key)));
    }
}
