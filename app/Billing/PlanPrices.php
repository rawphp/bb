<?php

namespace App\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * The plans' Stripe prices, kept for the paywall (SUB-002). billing:refresh-prices
 * reads them from Stripe every 15 minutes, so billing_show reads the kept copy.
 * A copy that is missing, or older than 15 minutes because the scheduler is not
 * running, is read again by one request, under a lock; the others go on with
 * the old copy, or without the plan when none is kept. A failed read keeps the
 * old copy and stops reading Stripe for a minute.
 */
final class PlanPrices
{
    /** Set for a minute after Stripe fails a price read. */
    public const DOWN = 'billing:stripe-down';

    /** How old a kept price may be before it is read again; billing:refresh-prices runs this often. */
    public const REFRESH_SECONDS = 900;

    public function __construct(private readonly StripeGateway $stripe) {}

    public static function key(string $price): string
    {
        return "billing:price:{$price}";
    }

    /**
     * The kept price, read again first when it is missing or older than the refresh
     * interval; null while there is none.
     *
     * @return array{amount: int, currency: string, interval: string, interval_count: int}|null
     */
    public function get(string $price): ?array
    {
        /** @var array{price: array{amount: int, currency: string, interval: string, interval_count: int}, at: int}|null $kept */
        $kept = Cache::get(self::key($price));
        // A copy in another shape (kept before it recorded its read time) counts as missing.
        if (! isset($kept['price'], $kept['at'])) {
            $kept = null;
        }
        $fresh = $kept !== null && CarbonImmutable::now()->getTimestamp() - $kept['at'] < self::REFRESH_SECONDS;
        if ($fresh || Cache::has(self::DOWN)) {
            return $kept['price'] ?? null;
        }

        $read = Cache::lock(self::key($price).':fetch', 30)->get(fn (): ?array => $this->refresh($price));

        return is_array($read) ? $read : ($kept['price'] ?? null);
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

        Cache::forever(self::key($price), ['price' => $read, 'at' => CarbonImmutable::now()->getTimestamp()]);

        return $read;
    }
}
