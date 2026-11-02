<?php

namespace Tests\Support;

use App\Billing\StripeClientGateway;
use App\Billing\StripeGateway;
use RuntimeException;

/** Stripe without the network: it records each call and answers with the prices a hosted instance sells (SUB-002). */
final class FakeStripe implements StripeGateway
{
    /** @var list<array{email: string, name: string, user_id: int}> */
    public array $customers = [];

    /** @var list<array{customer: string, price: string, trial_days: int|null, return_url: string}> */
    public array $checkouts = [];

    /** @var list<string> Checkout pages closed */
    public array $closed = [];

    /** @var array<string, string> Checkout pages the user paid => the subscription each made */
    public array $paid = [];

    /** @var list<array{customer: string, return_url: string}> */
    public array $portals = [];

    /** @var array<string, list<array<string, mixed>>> each customer's subscriptions, as Stripe would list them now */
    public array $subscriptions = [];

    /** Stripe cannot be reached: reading subscriptions and prices fails as the real gateway does. */
    public bool $down = false;

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

    public function checkout(string $customer, string $price, ?int $trialDays, string $returnUrl): array
    {
        $this->checkouts[] = ['customer' => $customer, 'price' => $price, 'trial_days' => $trialDays, 'return_url' => $returnUrl];
        $id = 'cs_test_'.count($this->checkouts);

        return ['id' => $id, 'url' => 'https://checkout.stripe.com/c/pay/'.$id];
    }

    public function closeCheckout(string $session): ?string
    {
        if (isset($this->paid[$session])) {
            return $this->paid[$session];
        }
        $this->closed[] = $session;

        return null;
    }

    public function portalUrl(string $customer, string $returnUrl): string
    {
        $this->portals[] = ['customer' => $customer, 'return_url' => $returnUrl];

        return 'https://billing.stripe.com/p/session/test_'.count($this->portals);
    }

    public function subscriptions(string $customer): array
    {
        if ($this->down) {
            throw new RuntimeException(StripeClientGateway::UNAVAILABLE);
        }

        return $this->subscriptions[$customer] ?? [];
    }

    public function price(string $price): array
    {
        if ($this->down) {
            throw new RuntimeException(StripeClientGateway::UNAVAILABLE);
        }

        return $this->prices[$price];
    }
}
