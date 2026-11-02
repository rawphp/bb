<?php

namespace App\Billing;

/**
 * The calls billing makes to Stripe (SUB-005, SUB-007, SUB-021). Tests bind a
 * fake; {@see StripeClientGateway} makes them over Stripe's API. A call Stripe
 * fails throws a RuntimeException that says only that payments are unavailable.
 */
interface StripeGateway
{
    /** Creates the account's Stripe customer and returns its ID. */
    public function createCustomer(string $email, string $name, int $userId): string;

    /**
     * Opens a Stripe Checkout page that subscribes the customer to the price, with a free trial when given (SUB-003).
     *
     * @return array{id: string, url: string} the Checkout session and the page to open
     */
    public function checkout(string $customer, string $price, ?int $trialDays, string $returnUrl): array;

    /** Closes a Checkout page if it is still open; true when the user had already paid on it. */
    public function closeCheckout(string $session): bool;

    /** A Stripe customer portal page, where the customer switches plan or cancels (SUB-007). */
    public function portalUrl(string $customer, string $returnUrl): string;

    /**
     * Every subscription the customer has, ended ones too, as Stripe holds them now.
     *
     * @return list<array<string, mixed>>
     */
    public function subscriptions(string $customer): array;

    /**
     * A recurring price as Stripe holds it (SUB-002).
     *
     * @return array{amount: int, currency: string, interval: string, interval_count: int}
     */
    public function price(string $price): array;
}
