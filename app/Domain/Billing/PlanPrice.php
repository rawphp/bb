<?php

namespace App\Domain\Billing;

use NumberFormatter;

/**
 * One plan's Stripe price, worded for the paywall (SUB-002): "$179.99 per 6
 * months", and what a plan longer than a month comes to a month.
 */
final readonly class PlanPrice
{
    /** The paywall's title for each plan. */
    public const TITLES = ['monthly' => 'Monthly', 'six_months' => '6 months', 'yearly' => 'Yearly'];

    /**
     * @param  int  $amount  in the currency's smallest unit, as Stripe sends it
     * @param  string  $interval  Stripe's recurring interval: day, week, month or year
     */
    public function __construct(
        public string $plan,
        public int $amount,
        public string $currency,
        public string $interval,
        public int $intervalCount,
    ) {}

    public function title(): string
    {
        return self::TITLES[$this->plan] ?? $this->plan;
    }

    public function priceText(): string
    {
        $period = $this->intervalCount === 1 ? $this->interval : "{$this->intervalCount} {$this->interval}s";

        return $this->money($this->amount)." per {$period}";
    }

    /** What a plan of more than a month comes to a month; null for a monthly plan. */
    public function detail(): ?string
    {
        $months = match ($this->interval) {
            'month' => $this->intervalCount,
            'year' => 12 * $this->intervalCount,
            default => null,
        };

        if ($months === null || $months <= 1) {
            return null;
        }

        return $this->money($this->amount / $months).' a month';
    }

    private function money(float|int $amount): string
    {
        $currency = strtoupper($this->currency);
        $formatter = new NumberFormatter('en', NumberFormatter::CURRENCY);
        $formatter->setTextAttribute(NumberFormatter::CURRENCY_CODE, $currency);
        $digits = (int) $formatter->getAttribute(NumberFormatter::FRACTION_DIGITS);

        return (string) $formatter->formatCurrency($amount / 10 ** $digits, $currency);
    }
}
