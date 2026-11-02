<?php

use App\Support\Env;

/*
| Optional Stripe billing (SUB-020 to SUB-024). Off unless BILLING_ENABLED=true:
| a self-hosted instance leaves it off, and then nothing below is read and no
| billing capability, webhook or paywall exists. The hosted instance turns it
| on with its Stripe keys and the price of each plan it sells (SUB-002).
| A setting that is missing or unreadable with billing on stops the app from
| booting, rather than run a paywall that cannot take a payment.
*/

$enabled = filter_var(Env::raw('BILLING_ENABLED') ?? 'false', FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
if ($enabled === null) {
    throw new InvalidArgumentException('BILLING_ENABLED must be true or false; it is "'.Env::raw('BILLING_ENABLED').'".');
}

$prices = [
    'monthly' => Env::raw('STRIPE_PRICE_MONTHLY'),
    'six_months' => Env::raw('STRIPE_PRICE_SIX_MONTHS'),
    'yearly' => Env::raw('STRIPE_PRICE_YEARLY'),
];

$secret = Env::raw('STRIPE_SECRET');
$webhookSecret = Env::raw('STRIPE_WEBHOOK_SECRET');

if ($enabled) {
    // Without the webhook secret no signature could be checked (SUB-021).
    foreach (['STRIPE_SECRET' => $secret, 'STRIPE_WEBHOOK_SECRET' => $webhookSecret] as $key => $value) {
        if ($value === null) {
            throw new InvalidArgumentException("{$key} must be set when BILLING_ENABLED is true.");
        }
    }
    if (array_filter($prices) === []) {
        throw new InvalidArgumentException('Set at least one of STRIPE_PRICE_MONTHLY, STRIPE_PRICE_SIX_MONTHS and STRIPE_PRICE_YEARLY when BILLING_ENABLED is true.');
    }
}

return [
    'enabled' => $enabled,

    'stripe' => [
        'secret' => $secret,
        'webhook_secret' => $webhookSecret,
    ],

    // The Stripe price ID of each plan the paywall sells (SUB-002). An empty one is not offered.
    'prices' => $prices,
];
