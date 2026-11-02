<?php

namespace App\Billing;

use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * The plans' Stripe prices, kept for the paywall (SUB-002). billing:refresh-prices
 * reads them from Stripe on a schedule, so billing_show reads the kept copy and
 * waits on Stripe only for a price never kept: once, by one request, while the
 * others go on without it. A failed read keeps the old copy and stops reading
 * Stripe for a minute.
 */
final class PlanPrices
{
    /** Set for a minute after Stripe fails a price read. */
    public const DOWN = 'billing:stripe-down';

    public function __construct(private readonly StripeGateway $stripe) {}

    public static function key(string $price): string
    {
        return "billing:price:{$price}";
    }

    /**
     * The kept price, or one read now when none is kept; null while there is none.
     *
     * @return array{amount: int, currency: string, interval: string, interval_count: int}|null
     */
    public function get(string $price): ?array
    {
        /** @var array{amount: int, currency: string, interval: string, interval_count: int}|null $kept */
        $kept = Cache::get(self::key($price));
        if ($kept !== null || Cache::has(self::DOWN)) {
            return $kept;
        }

        $read = Cache::lock(self::key($price).':fetch', 30)->get(fn (): ?array => $this->refresh($price));

        return is_array($read) ? $read : null;
    }

    /**
     * Reads the price from Stripe and keeps it until the next refresh; null, keeping the old copy, when Stripe fails.
     *
     * @return array{amount: int, currency: string, interval: string, interval_count: int}|null
     */
    public function refresh(string $price): ?array
    {
        try {
            $read = $this->stripe->price($price);
        } catch (RuntimeException) {
            Cache::put(self::DOWN, true, 60);

            return null;
        }

        Cache::forever(self::key($price), $read);

        return $read;
    }
}
