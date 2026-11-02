<?php

namespace Tests\Support;

use App\Billing\StripeGateway;

/** Stripe without the network: it records each call and answers with the prices a hosted instance sells (SUB-002). */
final class FakeStripe implements StripeGateway
{
    /** @var list<array{email: string, name: string, user_id: int}> */
    public array $customers = [];

    /** @var list<array{customer: string, price: string, trial_days: int|null, return_url: string}> */
    public array $checkouts = [];

    /** @var list<array{customer: string, return_url: string}> */
    public array $portals = [];

    /** @var array<string, list<array<string, mixed>>> each customer's subscriptions, as Stripe would list them now */
    public array $subscriptions = [];

    /** @var array<string, array{amount: int, currency: string, interval: string, interval_count: int}> */
    public array $prices = [
        'price_monthly' => ['amount' => 3499, 'currency' => 'usd', 'interval' => 'month', 'interval_count' => 1],
        'price_six_months' => ['amount' => 17999, 'currency' => 'usd', 'interval' => 'month', 'interval_count' => 6],
        'price_yearly' => ['amount' => 29999, 'currency' => 'usd', 'interval' => 'year', 'interval_count' => 1],
    ];

    public function createCustomer(string $email, string $name, int $userId): string
    {
        $this->customers[] = ['email' => $email, 'name' => $name, 'user_id' => $userId];

        return 'cus_test_'.count($this->customers);
    }

    public function checkoutUrl(string $customer, string $price, ?int $trialDays, string $returnUrl): string
    {
        $this->checkouts[] = ['customer' => $customer, 'price' => $price, 'trial_days' => $trialDays, 'return_url' => $returnUrl];

        return 'https://checkout.stripe.com/c/pay/cs_test_'.count($this->checkouts);
    }

    public function portalUrl(string $customer, string $returnUrl): string
    {
        $this->portals[] = ['customer' => $customer, 'return_url' => $returnUrl];

        return 'https://billing.stripe.com/p/session/test_'.count($this->portals);
    }

    public function subscriptions(string $customer): array
    {
        return $this->subscriptions[$customer] ?? [];
    }

    public function price(string $price): array
    {
        return $this->prices[$price];
    }
}
