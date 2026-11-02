<?php

namespace App\Domain\Progression;

/** "Most" in the flag rules: strictly more than half. */
final class Most
{
    /**
     * @template T
     *
     * @param  array<T>  $items
     * @param  callable(T): bool  $isIncluded
     */
    public static function of(array $items, callable $isIncluded): bool
    {
        return count(array_filter($items, $isIncluded)) * 2 > count($items);
    }
}
