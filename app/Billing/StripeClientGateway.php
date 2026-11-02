<?php

namespace App\Billing;

use Closure;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Stripe\Subscription;

/** {@see StripeGateway} over Stripe's API. */
final class StripeClientGateway implements StripeGateway
{
    public const UNAVAILABLE = 'Payments are unavailable right now. Try again shortly.';

    /** How long a Checkout page stays payable. */
    private const CHECKOUT_SECONDS = 3600;

    public function __construct(private readonly StripeClient $stripe) {}

    public function createCustomer(string $email, string $name, int $userId): string
    {
        return self::call(fn (): string => $this->stripe->customers->create([
            'email' => $email,
            'name' => $name,
            'metadata' => ['user_id' => (string) $userId],
        ])->id);
    }

    public function checkout(string $customer, string $price, ?int $trialDays, string $returnUrl): array
    {
        $params = [
            'mode' => 'subscription',
            'customer' => $customer,
            'line_items' => [['price' => $price, 'quantity' => 1]],
            // Sale, referral and gym codes are Stripe promotion codes, typed in Checkout (SUB-009).
            'allow_promotion_codes' => true,
            'success_url' => $returnUrl,
            'cancel_url' => $returnUrl,
            // A page left open closes within the hour, not Stripe's 24, so an old tab cannot sell a second subscription.
            'expires_at' => time() + self::CHECKOUT_SECONDS,
        ];
        if ($trialDays !== null) {
            $params['subscription_data'] = ['trial_period_days' => $trialDays];
        }

        $session = self::call(fn () => $this->stripe->checkout->sessions->create($params));

        return ['id' => $session->id, 'url' => (string) $session->url];
    }

    public function closeCheckout(string $session): ?string
    {
        return self::call(function () use ($session): ?string {
            $found = $this->stripe->checkout->sessions->retrieve($session);
            if ($found->status === 'open') {
                $this->stripe->checkout->sessions->expire($session);
            }

            $subscription = $found->subscription;

            return $found->status === 'complete' && $subscription !== null
                ? (is_string($subscription) ? $subscription : $subscription->id)
                : null;
        });
    }

    public function portalUrl(string $customer, string $returnUrl): string
    {
        return self::call(fn (): string => $this->stripe->billingPortal->sessions->create([
            'customer' => $customer,
            'return_url' => $returnUrl,
        ])->url);
    }

    public function subscriptions(string $customer): array
    {
        return self::call(fn (): array => array_map(
            fn (Subscription $subscription): array => $subscription->toArray(),
            $this->stripe->subscriptions->all(['customer' => $customer, 'status' => 'all', 'limit' => 100])->data,
        ));
    }

    public function price(string $price): array
    {
        $found = self::call(fn () => $this->stripe->prices->retrieve($price));

        return [
            'amount' => (int) $found->unit_amount,
            'currency' => $found->currency,
            'interval' => (string) $found->recurring?->interval,
            'interval_count' => (int) $found->recurring?->interval_count,
        ];
    }

    /**
     * Runs a Stripe call. A failure is reported for the operator and never shown:
     * Stripe's message can name the key or the price.
     *
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    private static function call(Closure $call): mixed
    {
        try {
            return $call();
        } catch (ApiErrorException $failed) {
            report($failed);

            throw new RuntimeException(self::UNAVAILABLE, previous: $failed);
        }
    }
}
