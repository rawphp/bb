<?php

namespace App\Billing;

use Illuminate\Support\Facades\Cache;
use Stripe\StripeClient;

/** {@see StripeGateway} over Stripe's API. */
final class StripeClientGateway implements StripeGateway
{
    /** How long a plan's price is kept before it is read from Stripe again. */
    private const PRICE_SECONDS = 3600;

    public function __construct(private readonly StripeClient $stripe) {}

    public function createCustomer(string $email, string $name, int $userId): string
    {
        return $this->stripe->customers->create([
            'email' => $email,
            'name' => $name,
            'metadata' => ['user_id' => (string) $userId],
        ])->id;
    }

    public function checkoutUrl(string $customer, string $price, ?int $trialDays, string $returnUrl): string
    {
        $params = [
            'mode' => 'subscription',
            'customer' => $customer,
            'line_items' => [['price' => $price, 'quantity' => 1]],
            // Sale, referral and gym codes are Stripe promotion codes, typed in Checkout (SUB-009).
            'allow_promotion_codes' => true,
            'success_url' => $returnUrl,
            'cancel_url' => $returnUrl,
        ];
        if ($trialDays !== null) {
            $params['subscription_data'] = ['trial_period_days' => $trialDays];
        }

        return (string) $this->stripe->checkout->sessions->create($params)->url;
    }

    public function portalUrl(string $customer, string $returnUrl): string
    {
        return $this->stripe->billingPortal->sessions->create([
            'customer' => $customer,
            'return_url' => $returnUrl,
        ])->url;
    }

    public function price(string $price): array
    {
        /** @var array{amount: int, currency: string, interval: string, interval_count: int} */
        return Cache::remember("billing:price:{$price}", self::PRICE_SECONDS, function () use ($price): array {
            $found = $this->stripe->prices->retrieve($price);

            return [
                'amount' => (int) $found->unit_amount,
                'currency' => $found->currency,
                'interval' => (string) $found->recurring?->interval,
                'interval_count' => (int) $found->recurring?->interval_count,
            ];
        });
    }
}
