<?php

namespace App\Domain\Shopping;

use DateTimeImmutable;
use DateTimeZone;

/**
 * SHOP-005: the lines ticked off in one date range. Each range keeps its own ticks. Days compare as
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
     * SHOP-001, SHOP-005: with no range asked for, the last range ticked in comes back while it still runs
     * through today; otherwise the list shows the next 7 days from today.
     *
     * @param  list<self>  $saved  the most recently ticked first
     */
    public static function rangeToShow(array $saved, DateTimeImmutable $today, DateTimeZone $timezone): DateRange
    {
        foreach ($saved as $ticked) {
            if (! $ticked->hasEnded($today, $timezone)) {
                return $ticked->range;
            }
        }

        return ShoppingList::defaultRange($today, $timezone);
    }

    /**
     * SHOP-005: the keys ticked in `$range`. Each range keeps its own ticks; a range never ticked has none.
     *
     * @param  list<self>  $saved
     * @return list<string>
     */
    public static function keysIn(array $saved, DateRange $range): array
    {
        foreach ($saved as $ticked) {
            if ($ticked->isFor($range)) {
                return $ticked->keys;
            }
        }

        return [];
    }

    /**
     * SHOP-005, OFF-030: tick a line off in `$range`, or take the tick back, given the ticks of that range.
     * The tick names the state it leaves, so the same tick twice changes nothing, and a tick in one range
     * never touches another's. An untick in a range with no ticks keeps nothing.
     */
    public static function check(?self $saved, DateRange $range, string $key, bool $ticked): ?self
    {
        if (! $ticked && $saved === null) {
            return null;
        }

        $keys = array_values(array_filter($saved->keys ?? [], fn (string $kept) => $kept !== $key));

        return new self($range, $ticked ? [...$keys, $key] : $keys);
    }

    /** SHOP-005: a range has ended once its last day is before today; its ticks then go. */
    public function hasEnded(DateTimeImmutable $today, DateTimeZone $timezone): bool
    {
        return $this->range->upper->format('Y-m-d') < $today->setTimezone($timezone)->format('Y-m-d');
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
